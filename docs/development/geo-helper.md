# Geo Helper

`Nene\Func\GeoHelper` — geographic distance calculation and bounding box using the Haversine formula.

## API

| Method | Description |
| --- | --- |
| `distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float` | Great-circle distance in kilometres. |
| `distanceMi(float $lat1, float $lon1, float $lat2, float $lon2): float` | Great-circle distance in miles. |
| `boundingBox(float $lat, float $lon, float $radiusKm): array` | Bounding box for proximity pre-filter. |

## Usage

```php
// Distance between Tokyo and Osaka
$km = GeoHelper::distanceKm(35.6895, 139.6917, 34.6937, 135.5023); // ≈ 396 km
$mi = GeoHelper::distanceMi(35.6895, 139.6917, 34.6937, 135.5023); // ≈ 246 mi

// Symmetric
GeoHelper::distanceKm(A, B) === GeoHelper::distanceKm(B, A)

// Bounding box for "within 10 km of Tokyo" proximity search
$box = GeoHelper::boundingBox(35.6895, 139.6917, 10.0);
// $box = ['minLat' => 35.5993, 'maxLat' => 35.7797, 'minLon' => 139.5737, 'maxLon' => 139.8097]

// Use in SQL as a fast pre-filter
$stmt = $pdo->prepare(
    'SELECT * FROM spots
     WHERE lat BETWEEN :minLat AND :maxLat
       AND lon BETWEEN :minLon AND :maxLon'
);
$stmt->execute($box);

// Then apply exact Haversine to filter by true distance
$results = array_filter($rows, fn ($row) =>
    GeoHelper::distanceKm($centerLat, $centerLon, $row['lat'], $row['lon']) <= 10.0
);
```

## Bounding box pattern

The bounding box is a fast rectangular pre-filter. It over-selects (corners of the box extend beyond the circle) but is cheap to compute and index-friendly in SQL. Always combine with exact distance filtering for accurate results.

```
         maxLat
    ┌────────────┐
    │    ●●●●    │
    │  ●●●●●●●  │ ← circle
    │    ●●●●    │
    └────────────┘
minLon         maxLon
```

## Formula

Uses the **Haversine formula** for great-circle distances on a sphere (Earth radius = 6371 km). Accurate to within ~0.3% for distances up to thousands of kilometres.

```
a = sin²(Δlat/2) + cos(lat1) · cos(lat2) · sin²(Δlon/2)
c = 2 · atan2(√a, √(1-a))
d = R · c
```

## Key design points

- **Static helper**: no state or DB dependency — pure math.
- **No external dependencies**: standard PHP math functions only.
- **Coordinate order**: `(lat, lon)` — not `(lon, lat)` as used by some GeoJSON libraries.
- **Boundary clamping**: `boundingBox()` clamps output to valid ranges (-90..90 lat, -180..180 lon).
