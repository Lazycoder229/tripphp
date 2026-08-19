<?php

declare(strict_types=1);

namespace Framework\View;

use Framework\Config\Env;
use Framework\Exception\NotFoundException;
use RuntimeException;

/**
 * ViewEngine
 * 
 * Lightweight, fast, Blade-style compiled template engine with layouts,
 * sections, stacks, asset helpers, CSRF directives, and template caching.
 * 
 * @package Framework\View
 */
final class ViewEngine
{
    private array $sections = [];
    private array $stacks = [];
    private ?string $currentSection = null;
    private ?string $currentPush = null;
    private ?string $currentPrepend = null;
    private ?string $layout = null;

    public function __construct(
        private readonly string $viewsPath,
        private readonly string $cachePath
    ) {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0775, true);
        }
    }

    /**
     * Renders a view template with the supplied data.
     *
     * @param string $view Dot notation view name (e.g. 'users.index' or 'layouts.app')
     * @param array $data Variables to expose to the view
     * @return string Rendered HTML output
     */
    public function render(string $view, array $data = []): string
    {
        return $this->renderView($view, $data, isSubView: false);
    }

    /**
     * Internal renderer supporting layout inheritance and sub-views.
     */
    public function renderView(string $view, array $data, bool $isSubView = false): string
    {
        $viewFile = $this->resolveViewFile($view);
        $compiledFile = $this->getCompiledPath($viewFile);

        if ($this->isExpired($viewFile, $compiledFile)) {
            $content = (string) file_get_contents($viewFile);
            $compiled = $this->compile($content);
            file_put_contents($compiledFile, $compiled, LOCK_EX);
        }

        if (!$isSubView) {
            $this->sections = [];
            $this->stacks = [];
            $this->layout = null;
            $this->currentSection = null;
            $this->currentPush = null;
            $this->currentPrepend = null;
        }

        // Render the view file
        $content = $this->evaluate($compiledFile, $data);

        // If the view specified a parent layout via @extends
        if ($this->layout !== null) {
            $layoutView = $this->layout;
            $this->layout = null; // Prevent infinite loop
            return $this->renderView($layoutView, $data, isSubView: true);
        }

        return $content;
    }

    /**
     * Resolves the view file path from dot notation.
     */
    private function resolveViewFile(string $view): string
    {
        $normalized = str_replace('.', '/', $view);
        $extensions = ['.php', '.view.php', '.trip.php', '.html'];

        foreach ($extensions as $ext) {
            $path = rtrim($this->viewsPath, '/') . '/' . $normalized . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        throw new NotFoundException("View [{$view}] not found in [{$this->viewsPath}].");
    }

    private function getCompiledPath(string $viewFile): string
    {
        return rtrim($this->cachePath, '/') . '/' . md5($viewFile) . '.php';
    }

    private function isExpired(string $viewFile, string $compiledFile): bool
    {
        if (!is_file($compiledFile)) {
            return true;
        }
        return filemtime($viewFile) > filemtime($compiledFile);
    }

    /**
     * Evaluates a compiled PHP template file within an isolated scope.
     */
    private function evaluate(string $compiledFile, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();

        try {
            include $compiledFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Compiles Blade-style template syntax into native PHP.
     */
    public function compile(string $template): string
    {
        // 1. Comments {{-- comment --}}
        $template = preg_replace('/\{\{--(.*?)--\}\}/s', '', $template);

        // 2. Raw output: {!! $var !!}
        $template = preg_replace('/\{\!!\s*(.+?)\s*\!!\}/s', '<?= $1; ?>', $template);

        // 3. Escaped output: {{ $var }} -> htmlspecialchars
        $template = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?= htmlspecialchars((string)($1), ENT_QUOTES, \'UTF-8\'); ?>', $template);

        // 4. Layout & Inheritance Directives
        $template = preg_replace('/@extends\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php $this->layout = \'$1\'; ?>', $template);

        // @section('name', 'value')
        $template = preg_replace('/@section\s*\(\s*[\'"](.+?)[\'"]\s*,\s*([^\)]+)\s*\)/', '<?php $this->setSection(\'$1\', $2); ?>', $template);

        // @section('name') ... @endsection
        $template = preg_replace('/@section\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php $this->startSection(\'$1\'); ?>', $template);
        $template = preg_replace('/@endsection/', '<?php $this->endSection(); ?>', $template);

        // @yield('name', 'default')
        $template = preg_replace('/@yield\s*\(\s*[\'"](.+?)[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/', '<?= $this->yieldSection(\'$1\', \'$2\'); ?>', $template);
        $template = preg_replace('/@yield\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?= $this->yieldSection(\'$1\'); ?>', $template);

        // 5. Stacks (@push, @prepend, @stack)
        $template = preg_replace('/@push\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php $this->startPush(\'$1\'); ?>', $template);
        $template = preg_replace('/@endpush/', '<?php $this->endPush(); ?>', $template);

        $template = preg_replace('/@prepend\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?php $this->startPrepend(\'$1\'); ?>', $template);
        $template = preg_replace('/@endprepend/', '<?php $this->endPrepend(); ?>', $template);

        $template = preg_replace('/@stack\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?= $this->yieldStack(\'$1\'); ?>', $template);

        // 6. Asset Directives (@css, @js, @script, @asset)
        $template = preg_replace('/@css\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<link rel="stylesheet" href="<?= $this->asset(\'$1\'); ?>">', $template);
        $template = preg_replace('/@js\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<script src="<?= $this->asset(\'$1\'); ?>"></script>', $template);
        $template = preg_replace('/@script\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<script src="<?= $this->asset(\'$1\'); ?>"></script>', $template);

        // @url('path') -> full absolute URL built from APP_URL
        $template = preg_replace('/@url\s*\(\s*[\'"](.*?)[\'"]\s*\)/', '<?= $this->url(\'$1\'); ?>', $template);
        $template = preg_replace('/@url\s*\(\s*\)/', '<?= $this->url(); ?>', $template);

        // 7. Security Directives: @csrf, @csrfMeta, @csrfJs, and @method('PUT')
        $template = preg_replace('/@csrfMeta/', '<meta name="csrf-token" content="<?= \Framework\View\View::csrfToken(); ?>">', $template);

        // @csrfJs — auto-inject the CSRF token into fetch() AND XMLHttpRequest (jQuery $.ajax)
        // Reads the token from <meta name="csrf-token">, sends it as X-CSRF-Token on POST/PUT/PATCH/DELETE.
        // This matches CsrfMiddleware which checks: $request->header('x-csrf-token') ?? $request->input('_csrf')
        $csrfJsCode = '<script>'
            . '(function(){'
            .   'var m=document.querySelector("meta[name=\'csrf-token\']");'
            .   'if(!m)return;'
            .   'var tk=m.getAttribute("content");'
            .   'var safe=["GET","HEAD","OPTIONS"];'
            // Patch fetch()
            .   'if(window.fetch){'
            .     'var _f=window.fetch;'
            .     'window.fetch=function(u,o){'
            .       'o=o||{};'
            .       'if(!safe.includes((o.method||"GET").toUpperCase())){'
            .         'if(o.headers instanceof Headers){o.headers.set("X-CSRF-Token",tk);}'
            .         'else if(o.headers&&typeof o.headers==="object"){o.headers["X-CSRF-Token"]=tk;}'
            .         'else{o.headers={"X-CSRF-Token":tk};}'
            .       '}'
            .       'return _f.call(this,u,o);'
            .     '};'
            .   '}'
            // Patch XMLHttpRequest (jQuery $.ajax, vanilla XHR)
            .   'var _o=XMLHttpRequest.prototype.open;'
            .   'XMLHttpRequest.prototype.open=function(mt){'
            .     'this._csrfMethod=mt;'
            .     'return _o.apply(this,arguments);'
            .   '};'
            .   'var _s=XMLHttpRequest.prototype.send;'
            .   'XMLHttpRequest.prototype.send=function(){'
            .     'if(this._csrfMethod&&!safe.includes(this._csrfMethod.toUpperCase())){'
            .       'this.setRequestHeader("X-CSRF-Token",tk);'
            .     '}'
            .     'return _s.apply(this,arguments);'
            .   '};'
            . '})();'
            . '</script>';
        $template = preg_replace('/@csrfJs/', $csrfJsCode, $template);

        $template = preg_replace('/@csrf/', '<input type="hidden" name="_csrf" value="<?= \Framework\View\View::csrfToken(); ?>">', $template);
        $template = preg_replace('/@method\s*\(\s*[\'"]([A-Z]+)[\'"]\s*\)/', '<input type="hidden" name="_method" value="$1">', $template);

        // 8. Includes: @include('view', ['key' => 'val'])
        $template = preg_replace('/@include\s*\(\s*[\'"](.+?)[\'"]\s*,\s*(\[.*?\])\s*\)/', '<?= $this->renderView(\'$1\', array_merge(get_defined_vars(), $2), true); ?>', $template);
        $template = preg_replace('/@include\s*\(\s*[\'"](.+?)[\'"]\s*\)/', '<?= $this->renderView(\'$1\', get_defined_vars(), true); ?>', $template);

        // 9. Control Structures
        // @if, @elseif, @else, @endif
        $template = preg_replace('/@if\s*\((.*?)\)/', '<?php if ($1): ?>', $template);
        $template = preg_replace('/@elseif\s*\((.*?)\)/', '<?php elseif ($1): ?>', $template);
        $template = preg_replace('/@else/', '<?php else: ?>', $template);
        $template = preg_replace('/@endif/', '<?php endif; ?>', $template);

        // @unless, @endunless
        $template = preg_replace('/@unless\s*\((.*?)\)/', '<?php if (!($1)): ?>', $template);
        $template = preg_replace('/@endunless/', '<?php endif; ?>', $template);

        // @isset, @endisset, @empty, @endempty
        $template = preg_replace('/@isset\s*\((.*?)\)/', '<?php if (isset($1)): ?>', $template);
        $template = preg_replace('/@endisset/', '<?php endif; ?>', $template);
        $template = preg_replace('/@empty\s*\((.*?)\)/', '<?php if (empty($1)): ?>', $template);
        $template = preg_replace('/@endempty/', '<?php endif; ?>', $template);

        // @foreach, @endforeach, @for, @endfor, @while, @endwhile
        $template = preg_replace('/@foreach\s*\((.*?)\)/', '<?php foreach ($1): ?>', $template);
        $template = preg_replace('/@endforeach/', '<?php endforeach; ?>', $template);
        $template = preg_replace('/@for\s*\((.*?)\)/', '<?php for ($1): ?>', $template);
        $template = preg_replace('/@endfor/', '<?php endfor; ?>', $template);
        $template = preg_replace('/@while\s*\((.*?)\)/', '<?php while ($1): ?>', $template);
        $template = preg_replace('/@endwhile/', '<?php endwhile; ?>', $template);

        // @php, @endphp
        $template = preg_replace('/@php\s*\((.*?)\)/', '<?php $1; ?>', $template);
        $template = preg_replace('/@php/', '<?php', $template);
        $template = preg_replace('/@endphp/', '?>', $template);

        return $template;
    }

    public function startSection(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw new RuntimeException("Cannot end a section without starting one.");
        }

        $this->sections[$this->currentSection] = (string) ob_get_clean();
        $this->currentSection = null;
    }

    public function setSection(string $name, string $content): void
    {
        $this->sections[$name] = $content;
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function startPush(string $name): void
    {
        $this->currentPush = $name;
        ob_start();
    }

    public function endPush(): void
    {
        if ($this->currentPush === null) {
            throw new RuntimeException("Cannot end push without starting one.");
        }

        $content = (string) ob_get_clean();
        $this->stacks[$this->currentPush][] = $content;
        $this->currentPush = null;
    }

    public function startPrepend(string $name): void
    {
        $this->currentPrepend = $name;
        ob_start();
    }

    public function endPrepend(): void
    {
        if ($this->currentPrepend === null) {
            throw new RuntimeException("Cannot end prepend without starting one.");
        }

        $content = (string) ob_get_clean();
        if (!isset($this->stacks[$this->currentPrepend])) {
            $this->stacks[$this->currentPrepend] = [];
        }
        array_unshift($this->stacks[$this->currentPrepend], $content);
        $this->currentPrepend = null;
    }

    public function yieldStack(string $name): string
    {
        if (empty($this->stacks[$name])) {
            return '';
        }
        return implode("\n", $this->stacks[$name]);
    }

    public function asset(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }
        return '/' . ltrim($path, '/');
    }

    /**
     * Builds a full, absolute URL from a path using APP_URL as the base.
     * Already-absolute paths (http://, https://, //) are returned as-is.
     */
    public function url(string $path = ''): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '//')) {
            return $path;
        }
        return Env::appUrl() . '/' . ltrim($path, '/');
    }

    public function clearCache(): int
    {
        $count = 0;
        foreach (glob($this->cachePath . '/*.php') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }
}