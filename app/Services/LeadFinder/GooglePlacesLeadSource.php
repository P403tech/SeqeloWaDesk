<?php

namespace App\Services\LeadFinder;

use Illuminate\Support\Facades\Http;

/**
 * Google Places lead source — activated when the workspace has pasted its own
 * Google Maps / Places API key (BYOK). Far richer than OSM: real business
 * phone numbers, websites, ratings and near-complete coverage.
 *
 * TWO APIs, deliberately.
 *
 * Google stopped enabling the legacy Places web service
 * (maps.googleapis.com/maps/api/place) for projects created after
 * 2025-03-01. A key minted today is perfectly valid and still gets
 * REQUEST_DENIED from it — which is why "my Google key won't save" was
 * reported: the save probe called the retired endpoint and refused a good key.
 *
 * So Places API (New) is tried first, and the legacy service is kept as a
 * fallback for older projects that only have THAT one enabled. Between them
 * every key works, old or new.
 *
 * The New API is also cheaper here: a FieldMask returns phone and website
 * inside the search response, so the per-result Place Details call the legacy
 * path needs (and has to ration with $detailsCap) disappears entirely.
 */
class GooglePlacesLeadSource
{
    /** Legacy web service — retired for projects created after 2025-03-01. */
    private const BASE = 'https://maps.googleapis.com/maps/api/place';

    /** Places API (New) — the only one available to recent keys. */
    private const BASE_V1 = 'https://places.googleapis.com/v1';

    /**
     * Fields pulled back from the New API. Phone + website inline is the whole
     * reason this path needs no Details calls.
     */
    private const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,'
        . 'places.location,places.rating,places.primaryTypeDisplayName,places.types,'
        . 'places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri';

    /** Set once a New-API call succeeds or fails, so we don't re-probe per page. */
    private ?bool $newApiWorks = null;

    public function __construct(private string $apiKey)
    {
    }

    /** Text search: "category in place". */
    public function search(string $category, string $place, int $limit = 60, int $detailsCap = 24): array
    {
        $q = trim($category . ' in ' . $place);

        // New API first — the only one a post-2025 key can use.
        $new = $this->searchTextV1($q, $limit);
        if ($new !== null) {
            return ['ok' => true, 'leads' => $new];
        }

        return $this->collect(
            fn () => $this->textSearch($q),
            $limit,
            $detailsCap,
        );
    }

    /** Bbox → nearby search around its centre with a radius covering it. */
    public function searchBbox(string $category, float $s, float $w, float $n, float $e, int $limit = 60, int $detailsCap = 24): array
    {
        $lat = ($s + $n) / 2;
        $lng = ($w + $e) / 2;
        // Rough radius (m) = half the box diagonal, clamped to Google's 50km max.
        $radius = (int) min(50000, max(500, $this->haversine($s, $w, $n, $e) / 2));

        return $this->searchAround($category, $lat, $lng, $radius, $limit, $detailsCap);
    }

    /** Nearby search in a radius around a point. */
    public function searchAround(string $category, float $lat, float $lng, int $radius = 3000, int $limit = 60, int $detailsCap = 24): array
    {
        $radius = max(200, min(50000, $radius));

        // New API: searchText with a location BIAS rather than searchNearby.
        // searchNearby (New) takes only structured includedTypes, so a free-text
        // category like "dental clinic" cannot be expressed there — biased text
        // search keeps the operator's own wording working.
        $new = $this->searchTextV1(
            trim($category) !== '' ? trim($category) : 'business',
            $limit,
            ['circle' => [
                'center' => ['latitude' => $lat, 'longitude' => $lng],
                'radius' => (float) $radius,
            ]],
        );
        if ($new !== null) {
            return ['ok' => true, 'leads' => $new];
        }

        return $this->collect(
            fn () => $this->nearby($category, $lat, $lng, $radius),
            $limit,
            $detailsCap,
        );
    }

    /* ---- internals ---- */

    private function collect(callable $fetch, int $limit, int $detailsCap): array
    {
        try {
            $results = $fetch();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'source_unavailable', 'leads' => []];
        }
        if ($results === null) {
            return ['ok' => false, 'error' => 'google_error', 'leads' => []];
        }

        $leads   = [];
        $details = 0;
        foreach (array_slice($results, 0, $limit) as $p) {
            $phone = null;
            $website = null;
            // A details call gets phone + website (cost per call — capped).
            if ($details < $detailsCap && ! empty($p['place_id'])) {
                $d = $this->details($p['place_id']);
                $phone   = $d['phone'] ?? null;
                $website = $d['website'] ?? null;
                $details++;
            }

            $leads[] = [
                'source'      => 'google',
                'external_id' => (string) ($p['place_id'] ?? ($p['reference'] ?? '')),
                'name'        => $p['name'] ?? null,
                'category'    => isset($p['types'][0]) ? ucwords(str_replace('_', ' ', $p['types'][0])) : null,
                'phone'       => $phone,
                'phone_e164'  => $phone ? preg_replace('/\D+/', '', $phone) : null,
                'email'       => null, // Google Places doesn't expose email.
                'website'     => $website,
                'address'     => $p['formatted_address'] ?? ($p['vicinity'] ?? null),
                'lat'         => $p['geometry']['location']['lat'] ?? null,
                'lng'         => $p['geometry']['location']['lng'] ?? null,
                'rating'      => $p['rating'] ?? null,
            ];
        }

        return ['ok' => true, 'leads' => $leads];
    }

    private function textSearch(string $query): ?array
    {
        $res = Http::timeout(20)->get(self::BASE . '/textsearch/json', [
            'query' => $query, 'key' => $this->apiKey,
        ]);
        $j = $res->json();
        if (($j['status'] ?? '') !== 'OK' && ($j['status'] ?? '') !== 'ZERO_RESULTS') {
            return null;
        }

        return $j['results'] ?? [];
    }

    private function nearby(string $category, float $lat, float $lng, int $radius): ?array
    {
        $params = ['location' => "$lat,$lng", 'radius' => $radius, 'key' => $this->apiKey];
        if (trim($category) !== '') {
            $params['keyword'] = $category;
        }
        $res = Http::timeout(20)->get(self::BASE . '/nearbysearch/json', $params);
        $j = $res->json();
        if (($j['status'] ?? '') !== 'OK' && ($j['status'] ?? '') !== 'ZERO_RESULTS') {
            return null;
        }

        return $j['results'] ?? [];
    }

    private function details(string $placeId): array
    {
        try {
            $res = Http::timeout(12)->get(self::BASE . '/details/json', [
                'place_id' => $placeId,
                'fields'   => 'formatted_phone_number,international_phone_number,website',
                'key'      => $this->apiKey,
            ]);
            $r = $res->json()['result'] ?? [];

            return [
                'phone'   => $r['international_phone_number'] ?? ($r['formatted_phone_number'] ?? null),
                'website' => $r['website'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Places API (New) text search, paged up to $limit.
     *
     * @param  array|null  $locationBias  optional {circle:{center,radius}}
     * @return array<int,array>|null  null = New API unavailable, use legacy
     */
    private function searchTextV1(string $query, int $limit, ?array $locationBias = null): ?array
    {
        if ($this->newApiWorks === false) {
            return null;   // already established this key can't use it
        }

        $leads     = [];
        $pageToken = null;
        $pages     = 0;

        do {
            $body = [
                // 20 is the New API's per-page maximum.
                'textQuery'      => $query,
                'maxResultCount' => min(20, max(1, $limit - count($leads))),
            ];
            if ($locationBias) $body['locationBias'] = $locationBias;
            if ($pageToken)    $body['pageToken']    = $pageToken;

            try {
                $res = Http::timeout(20)
                    ->withHeaders([
                        'X-Goog-Api-Key'   => $this->apiKey,
                        'X-Goog-FieldMask' => self::FIELD_MASK . ',nextPageToken',
                    ])
                    ->post(self::BASE_V1 . '/places:searchText', $body);
            } catch (\Throwable $e) {
                $this->newApiWorks = false;

                return null;
            }

            if (! $res->successful()) {
                // 403 / SERVICE_DISABLED means this project has the New API
                // switched off — fall back rather than fail the whole search.
                $this->newApiWorks = false;

                return null;
            }

            $this->newApiWorks = true;

            foreach ((array) $res->json('places', []) as $p) {
                $phone = $p['internationalPhoneNumber'] ?? ($p['nationalPhoneNumber'] ?? null);

                $leads[] = [
                    'source'      => 'google',
                    'external_id' => (string) ($p['id'] ?? ''),
                    'name'        => $p['displayName']['text'] ?? null,
                    'category'    => $p['primaryTypeDisplayName']['text']
                        ?? (isset($p['types'][0]) ? ucwords(str_replace('_', ' ', $p['types'][0])) : null),
                    'phone'       => $phone,
                    'phone_e164'  => $phone ? preg_replace('/\D+/', '', $phone) : null,
                    'email'       => null,   // Places exposes no email, old or new
                    'website'     => $p['websiteUri'] ?? null,
                    'address'     => $p['formattedAddress'] ?? null,
                    'lat'         => $p['location']['latitude'] ?? null,
                    'lng'         => $p['location']['longitude'] ?? null,
                    'rating'      => $p['rating'] ?? null,
                ];
            }

            $pageToken = (string) ($res->json('nextPageToken') ?? '') ?: null;
            $pages++;
        } while ($pageToken && count($leads) < $limit && $pages < 3);

        return array_slice($leads, 0, $limit);
    }

    /**
     * Quick validity probe for the settings screen.
     *
     * Accepts a key that works on EITHER API. Probing only the legacy service
     * is what rejected valid post-2025 keys and produced "could not be saved".
     */
    public function validate(): bool
    {
        // New API first.
        try {
            $res = Http::timeout(12)
                ->withHeaders([
                    'X-Goog-Api-Key'   => $this->apiKey,
                    'X-Goog-FieldMask' => 'places.id',
                ])
                ->post(self::BASE_V1 . '/places:searchText', [
                    'textQuery'      => 'restaurant',
                    'maxResultCount' => 1,
                ]);

            // 200 = usable. An empty result set is still a working key.
            if ($res->successful()) {
                return true;
            }
        } catch (\Throwable $e) {
            // fall through to the legacy probe
        }

        // Legacy, for older projects that only have that one enabled.
        try {
            $res = Http::timeout(12)->get(self::BASE . '/textsearch/json', [
                'query' => 'restaurant', 'key' => $this->apiKey,
            ]);
            $status = $res->json()['status'] ?? 'ERR';

            return in_array($status, ['OK', 'ZERO_RESULTS'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
