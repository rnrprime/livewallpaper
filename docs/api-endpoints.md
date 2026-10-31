# API Endpoints

Base prefix: `/api/v1`

All current API endpoints are `GET` endpoints, so there is no JSON request body required for any of them.

## 1. Get Categories

- Method: `GET`
- URL: `/api/v1/categories`
- Query parameters: none
- JSON body: none

### Success response

```json
{
  "data": [
    {
      "id": 1,
      "name": "Nature",
      "slug": "nature",
      "thumbnail": "https://example.com/storage/categories/example.png"
    }
  ]
}
```

### Notes

- Categories are ordered by name.
- `thumbnail` is returned as a full public URL when available.

## 2. Get Home / Explore Data

- Method: `GET`
- URL: `/api/v1/home`
- Query parameters: none
- JSON body: none

### Success response

```json
{
  "data": [
    {
      "category": {
        "id": 1,
        "name": "Nature",
        "slug": "nature",
        "thumbnail": "https://example.com/storage/categories/example.png"
      },
      "wallpapers": [
        {
          "id": 10,
          "title": "Forest",
          "preview_image": "https://example.com/storage/wallpapers/images/example.png",
          "file_url": "https://example.com/storage/wallpapers/videos/example.mp4",
          "is_premium": false
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5
  }
}
```

### Notes

- Categories are paginated with `10` items per page.
- Each category includes up to `3` latest wallpapers.
- `thumbnail`, `preview_image`, and `file_url` are returned as full public URLs.

## 3. Get Wallpapers

- Method: `GET`
- URL: `/api/v1/wallpapers`
- Query parameters:
  - `category_id` (optional, integer)
- JSON body: none

### Example requests

- `/api/v1/wallpapers`
- `/api/v1/wallpapers?category_id=1`

### Success response

```json
{
  "data": [
    {
      "id": 1,
      "title": "Forest",
      "preview_image": "https://example.com/storage/wallpapers/images/example.png",
      "file_url": "https://example.com/storage/wallpapers/videos/example.mp4",
      "is_premium": false,
      "category": {
        "id": 1,
        "name": "Nature"
      }
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/wallpapers?page=1",
    "last": "https://example.com/api/v1/wallpapers?page=3",
    "prev": null,
    "next": "https://example.com/api/v1/wallpapers?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "path": "https://example.com/api/v1/wallpapers",
    "per_page": 20,
    "to": 20,
    "total": 60
  }
}
```

### Error responses

#### Invalid category id type

Returned when `category_id` is not an integer.

Status: `422 Unprocessable Entity`

```json
{
  "message": "The category id field must be an integer.",
  "errors": {
    "category_id": [
      "The category id field must be an integer."
    ]
  }
}
```

#### Category not found

Returned when `category_id` is provided as an integer but no category exists with that id.

Status: `404 Not Found`

```json
{
  "data": null,
  "message": "Category not found"
}
```

### Notes

- Wallpapers are paginated with `20` items per page.
- Wallpapers are sorted by latest first.
- Category data is eager-loaded and returned inside each wallpaper item.
- `preview_image` and `file_url` are returned as full public URLs.

## 4. Get Single Wallpaper

- Method: `GET`
- URL: `/api/v1/wallpapers/{wallpaper}`
- Path parameters:
  - `wallpaper` (required, integer wallpaper id)
- Query parameters: none
- JSON body: none

### Example request

- `/api/v1/wallpapers/1`

### Success response

```json
{
  "data": {
    "id": 1,
    "title": "Forest",
    "preview_image": "https://example.com/storage/wallpapers/images/example.png",
    "file_url": "https://example.com/storage/wallpapers/videos/example.mp4",
    "is_premium": false,
    "category": {
      "id": 1,
      "name": "Nature"
    }
  }
}
```

### Notes

- The route only accepts numeric wallpaper ids.
- Category data is loaded and included in the response.

