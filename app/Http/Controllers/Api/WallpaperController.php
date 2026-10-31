<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WallpaperResource;
use App\Models\Category;
use App\Models\Wallpaper;
use App\Support\CatalogCache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class WallpaperController extends Controller
{
    /** Columns the resource renders; the feed never loads more. */
    private const COLUMNS = ['id', 'title', 'category_id', 'preview_image', 'preview_video', 'file_url', 'is_premium'];

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,40'],
            'page' => ['sometimes', 'integer', 'between:1,10000', 'prohibits:cursor'],
            'cursor' => ['sometimes', 'string', 'max:2048'],
            'sort' => ['sometimes', 'in:newest'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $category = isset($validated['category_id']) ? (int) $validated['category_id'] : null;
        $search = trim($validated['q'] ?? '');
        $limit = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $scope = hash('sha256', json_encode([$category, $search, 'newest']));
        $after = isset($validated['cursor']) ? $this->cursorPosition($validated['cursor'], $scope) : null;
        // Links echo only the parameters that shape the feed, so identical feeds share one cached body.
        $linkQuery = array_filter([
            'category_id' => $category,
            'q' => $search !== '' ? $search : null,
            'per_page' => isset($validated['per_page']) ? $limit : null,
            'sort' => $validated['sort'] ?? null,
        ], fn ($value) => $value !== null);

        $parameters = compact('category', 'search', 'limit', 'page', 'after', 'linkQuery');

        return CatalogCache::respond($request, 'wallpapers', $parameters, function () use ($request, $category, $search, $limit, $page, $after, $scope, $linkQuery) {
            if ($category !== null && ! Category::whereKey($category)->exists()) {
                return null;
            }

            $query = Wallpaper::query()->available()->select(self::COLUMNS)->with('category:id,name')
                ->when($category !== null, fn ($q) => $q->where('category_id', $category))
                ->when($search !== '', fn ($q) => $q->whereRaw("LOWER(title) LIKE LOWER(?) ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']))
                ->orderByDesc('id');

            $paginator = $after !== null
                ? $query->where('id', '<', $after)->simplePaginate($limit, ['*'], 'page', 1)
                : $query->paginate($limit, ['*'], 'page', $page);
            $paginator->withPath($request->url())->appends($linkQuery);

            $hasMore = $paginator->hasMorePages();
            $nextCursor = $hasMore ? Crypt::encryptString(json_encode(['id' => $paginator->last()->id, 'scope' => $scope])) : null;
            $payload = WallpaperResource::collection($paginator)->additional([
                'meta' => ['next_cursor' => $nextCursor, 'has_more' => $hasMore],
            ])->response($request)->getData(true);

            if ($after !== null) {
                $base = $request->url().'?';
                $payload['links'] = [
                    'first' => $base.http_build_query($linkQuery),
                    'last' => null,
                    'prev' => null,
                    'next' => $hasMore ? $base.http_build_query($linkQuery + ['cursor' => $nextCursor]) : null,
                ];
            }

            return $payload;
        });
    }

    public function show(Request $request, string $wallpaper): Response
    {
        $id = (int) $wallpaper;

        return CatalogCache::respond($request, 'wallpaper', ['id' => $id], function () use ($request, $id) {
            $model = Wallpaper::query()->available()->select(self::COLUMNS)->with('category:id,name')->find($id);

            return $model === null ? null : ['data' => (new WallpaperResource($model))->resolve($request)];
        });
    }

    /** The last ID a cursor has seen, validated against the filters it was issued for. */
    private function cursorPosition(string $cursor, string $scope): int
    {
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ($decoded['scope'] ?? null) !== $scope || ! is_int($decoded['id'] ?? null) || $decoded['id'] < 1) {
                throw new \UnexpectedValueException;
            }

            return $decoded['id'];
        } catch (DecryptException|\JsonException|\UnexpectedValueException) {
            throw ValidationException::withMessages(['cursor' => ['This cursor is invalid for the selected filters. Refresh the feed.']]);
        }
    }
}
