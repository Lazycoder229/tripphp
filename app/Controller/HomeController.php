<?php

declare(strict_types=1);

namespace App\Controller;

use Framework\Http\Request;
use Framework\Http\Response;
use Framework\Routing\Attribute\Delete;
use Framework\Routing\Attribute\Get;
use Framework\Routing\Attribute\Post;
use Framework\Routing\Attribute\Put;
use Framework\Routing\Attribute\Route;

/**
 * Pure HTTP layer for users: parses the Request, calls UserService, wraps
 * the result in a Response. No Model, query builder, DB access,
 * validation, id checking, or existence checking here — that all lives in
 * UserService, and any failure (NotFoundException, ValidationException)
 * is left to bubble up to the global Handler, which renders it as JSON.
 * Nothing in this class branches on the outcome of a service call.
 *
 * GET    /users       list all users
 * GET    /users/{id}  show one user
 * POST   /users        create a user
 * PUT    /users/{id}  update a user
 * DELETE /users/{id}  delete a user
 */
#[Route('/')]
class HomeController
{


    #[Get('/')]
    public function index(Request $request): Response
    {
        return Response::json(['message' => 'Hello World!']);
    }

   
}