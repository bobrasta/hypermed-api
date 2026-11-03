<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Contracts\UserDirectory;
use Hypermed\Messaging\Http\Requests\SearchUsersRequest;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(SearchUsersRequest $request, UserDirectory $directory): JsonResponse
    {
        return ApiResponse::success($directory->search(
            $request->validated('search'),
            (int) ($request->validated('limit') ?? 20),
            $this->userId($request),
        ));
    }
}
