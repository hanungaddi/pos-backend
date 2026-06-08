<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Return a standardized paginated response.
     */
    protected function responsePaginated($paginator): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Return a standardized single item/mutation response.
     */
    protected function responseSuccess($data = null, string $message = 'Operation successful.', int $code = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'data' => $data,
            'message' => $message,
            'status' => 'success',
        ], $code);
    }
}
