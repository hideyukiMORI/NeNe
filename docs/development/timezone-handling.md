# Timezone Handling

How to accept, validate, store, and render timezone-aware datetimes in NeNe APIs. Pair with **`docs/development/temporal-data.md`** for date-range storage patterns.

## The core rule: store UTC, display local

All datetimes stored in the database must be in UTC. Conversion to the user's local time happens at read time, not write time. This avoids ambiguity during DST transitions and makes sorting and comparison straightforward.

```
Client input:  "2026-11-01T01:30:00"  +  "America/New_York"
                        ↓
      Convert to UTC before storing
                        ↓
DB row:         "2026-11-01 05:30:00"   (UTC)
```

## IANA timezone validation

**Do not rely on PHP's `DateTimeZone` constructor alone.** It silently accepts timezone abbreviations (`"EST"`, `"PST"`) that are not canonical IANA identifiers. The constructor does not throw an exception for them:

```php
new DateTimeZone('EST');    // succeeds — no exception
new DateTimeZone('Foobar'); // throws: Unknown or bad timezone
```

But `"EST"` is not an IANA identifier — it is a legacy abbreviation. DST-unaware, fragile, and not in `DateTimeZone::listIdentifiers()`.

**Always validate with membership check:**

```php
function isValidIanaTimezone(string $tz): bool
{
    return in_array($tz, \DateTimeZone::listIdentifiers(), true);
}

// Usage
$tz = trim((string)($this->REQUEST_JSON['timezone'] ?? ''));
if (!isValidIanaTimezone($tz)) {
    return $this->API_RESPONSE->failure('EVENT-TIMEZONE-INVALID');
}
```

## Local → UTC conversion

```php
function localToUtc(string $localDatetime, string $ianaTimezone): string
{
    $dt = new \DateTimeImmutable($localDatetime, new \DateTimeZone($ianaTimezone));
    $dt = $dt->setTimezone(new \DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

// Example
echo localToUtc('2026-07-15 10:00:00', 'America/New_York');
// → "2026-07-15 14:00:00"  (New York is UTC-4 in July, EDT)
```

## UTC → local display

```php
function utcToLocal(string $utcDatetime, string $ianaTimezone): string
{
    $dt = new \DateTimeImmutable($utcDatetime, new \DateTimeZone('UTC'));
    $dt = $dt->setTimezone(new \DateTimeZone($ianaTimezone));
    return $dt->format('Y-m-d H:i:s');
}
```

## Schema

Store `started_at_utc` as a `DATETIME`-equivalent string (`YYYY-MM-DD HH:MM:SS`). Also store the original timezone name so it can be re-expressed later:

```php
// class/xion/SchemaDefinition.php

'events' => [
    'columns' => [
        'id'           => ['type' => 'pk-bigint'],
        'created_at'   => ['type' => 'datetime-now'],
        'updated_at'   => ['type' => 'datetime-touch'],
        'title'        => ['type' => 'varchar:255'],
        'timezone'     => ['type' => 'varchar:64'],     // IANA name, e.g. "Asia/Tokyo"
        'started_at'   => ['type' => 'varchar:19'],     // UTC, "YYYY-MM-DD HH:MM:SS"
    ],
    'indexes' => [
        'events_started_at_index' => ['started_at'],
    ],
],
```

## Controller: create endpoint

```php
public function indexPostRest(): array
{
    $title    = trim((string)($this->REQUEST_JSON['title']    ?? ''));
    $tz       = trim((string)($this->REQUEST_JSON['timezone'] ?? ''));
    $localDt  = trim((string)($this->REQUEST_JSON['start']    ?? ''));

    if ($title === '') {
        return $this->API_RESPONSE->failure('EVENT-TITLE-REQUIRED');
    }
    if (!isValidIanaTimezone($tz)) {
        return $this->API_RESPONSE->failure('EVENT-TIMEZONE-INVALID');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $localDt)) {
        return $this->API_RESPONSE->failure('EVENT-START-INVALID');
    }

    $utcDt = localToUtc($localDt, $tz);
    $event = (new Database\EventMapper())->create($title, $tz, $utcDt);

    return $this->API_RESPONSE->success(['event' => $this->normalizeRow($event)]);
}
```

## Controller: list with optional timezone conversion

Accept an optional `?timezone=` query parameter to convert stored UTC times to a local view:

```php
public function indexGetRest(): array
{
    $outputTz = $this->REQUEST->getParam('timezone', '');
    if ($outputTz !== '' && !isValidIanaTimezone($outputTz)) {
        return $this->API_RESPONSE->failure('EVENT-TIMEZONE-INVALID');
    }

    $events = (new Database\EventMapper())->findAll();

    if ($outputTz !== '') {
        foreach ($events as &$event) {
            $event['started_at_local'] = utcToLocal($event['started_at'], $outputTz);
            $event['display_timezone'] = $outputTz;
        }
        unset($event);
    }

    return $this->API_RESPONSE->success(['events' => $events]);
}
```

## DST edge cases

### Ambiguous times (clock falls back)

When a clock falls back (e.g., `America/New_York` in November), the local time `01:30 AM` occurs twice:
- First occurrence: EDT (UTC-4) → 05:30 UTC
- Second occurrence: EST (UTC-5) → 06:30 UTC

PHP's `DateTimeImmutable` resolves ambiguous times to the **first occurrence** (the pre-transition offset). If your application requires disambiguation (e.g., flight scheduling), collect the UTC offset explicitly from the client instead of relying on timezone name + local time alone.

### Non-existent times (clock springs forward)

When a clock springs forward (e.g., `2026-03-08 02:30:00 America/New_York`), the local time does not exist — the clock jumps from 02:00 to 03:00. PHP silently adjusts to the nearest valid time. For user-facing input, validate that the resulting UTC → local round-trip equals the original input:

```php
$reconstructed = utcToLocal(localToUtc($localDt, $tz), $tz);
if ($reconstructed !== $localDt) {
    return $this->API_RESPONSE->failure('EVENT-START-NONEXISTENT'); // DST gap
}
```

## Error codes

```php
// config/error_codes.php
'EVENT-TITLE-REQUIRED'      => ['message' => 'Event title is required.',                     'httpStatus' => 400],
'EVENT-TIMEZONE-INVALID'    => ['message' => 'Timezone must be a valid IANA timezone name.',  'httpStatus' => 400],
'EVENT-START-INVALID'       => ['message' => 'Event start must be YYYY-MM-DDTHH:MM:SS.',     'httpStatus' => 400],
'EVENT-START-NONEXISTENT'   => ['message' => 'That local time does not exist (DST gap).',    'httpStatus' => 422],
```

## Summary

| Concern | Recommended approach |
|---|---|
| Store datetime | UTC in `VARCHAR(19)` (`YYYY-MM-DD HH:MM:SS`) |
| Store timezone | IANA name in `VARCHAR(64)` alongside the UTC datetime |
| Validate input timezone | `in_array($tz, DateTimeZone::listIdentifiers(), true)` |
| Convert local → UTC | `DateTimeImmutable($local, new DateTimeZone($tz))->setTimezone(UTC)` |
| Display in local time | `setTimezone(new DateTimeZone($outputTz))->format(...)` |
| DST ambiguous time | Document that PHP picks first occurrence; ask for explicit UTC offset for precision |
| DST gap (spring-forward) | Round-trip check: `utcToLocal(localToUtc(t, tz), tz) === t` |

## Related

- `docs/development/temporal-data.md` — date-range (effective_from/effective_to) patterns
- `docs/development/booking-systems.md` — datetime-based slot booking
