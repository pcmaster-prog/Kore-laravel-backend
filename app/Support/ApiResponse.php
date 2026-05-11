<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApiResponse
{
    public static function ok($data = null, array $meta = [], string $message = 'ok', int $status = 200)
    {
        $payload = [];
        if (!is_null($data)) $payload['data'] = $data;
        if (!empty($meta)) $payload['meta'] = $meta;
        if ($message) $payload['message'] = $message;
        return response()->json($payload, $status);
    }

    public static function paginated(LengthAwarePaginator $p, string $message = 'ok')
    {
        return response()->json([
            'data' => $p->items(),
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
            'message' => $message,
        ]);
    }

    public static function error(string $message, int $status = 422, array $errors = [])
    {
    $payload = ['message' => $message];
    if (!empty($errors)) $payload['errors'] = $errors;
    return response()->json($payload, $status);
        }

}
