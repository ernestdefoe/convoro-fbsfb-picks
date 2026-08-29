<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

/**
 * The one place Picks talks to anything off this machine.
 *
 * 🚨 Every call has a hard CONNECT timeout and a hard READ timeout, and there
 * is no way to make a call without both. Convoro has a standing rule about
 * outbound calls, written after an uncapped one in queued work took a live
 * customer's site down: a socket that never answers holds a PHP worker, a
 * worker holds its database connection, and a handful of those is a site that
 * looks down while nothing in it has grown.
 *
 * 🚨 Failure is a VALUE, never an exception. A status of 0 means nobody
 * answered. Everything above this class has to be able to tell "the provider
 * says this game has not started" apart from "the provider did not answer",
 * because the first is a fact to store and the second must leave every row
 * exactly as it was. A pick'em that treats an outage as news wipes a week of
 * scores.
 *
 * 🚨 Only ever called from the queue. Nothing here is reachable from a
 * controller — see the tests, which check that by reading the source.
 *
 * Written against curl directly, matching core's OpenSearch and webhook modules
 * and OnAir's identical class. Convoro ships no HTTP library and is not growing
 * a dependency for one verb.
 *
 * 🚨 Not `final`, so the tests can stand a fake in front of it and exercise
 * every failure path with no provider anywhere. That is the whole reason; there
 * is no other subclass and there should not be one.
 */
class Http
{
    /**
     * 🚨 Short, and deliberately so even though this runs in the worker.
     *
     * A provider that has gone away resolves and then hangs. The schedule fires
     * again in a minute and the sync has a list of weeks to get through, so
     * waiting thirty seconds on the first — which is what the original's Guzzle
     * timeout allowed — means the rest are never fetched at all.
     */
    private const CONNECT_TIMEOUT = 4;

    /**
     * Longer than OnAir's, and for a real reason: a CFBD week of games is a
     * few hundred kilobytes of JSON rather than a one-line status, and the
     * ESPN scoreboard on a Saturday is larger still. Still short enough that a
     * whole capped run cannot outlast the gap between two scheduled ticks.
     */
    private const READ_TIMEOUT = 12;

    /**
     * A GET, as a decoded JSON body.
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<mixed>} status, decoded body.
     *         Status 0 means nobody answered.
     */
    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        if (!$this->usable() || !preg_match('#^https://#i', $url)) {
            return [0, []];
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return [0, []];
        }

        $lines = ['Accept: application/json'];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,

            /*
             * 🚨 Redirects are not followed, and the scheme is pinned to HTTPS
             * above. One of these requests carries an API key in a header; a
             * provider address that answers with a redirect somewhere else is
             * not a case worth honouring, it is how a credential ends up being
             * handed to whatever a parking page points at.
             */
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $lines,

            /*
             * 🚨 Say who is calling, because ESPN refuses anybody who does not.
             *
             * PHP's cURL extension sends NO User-Agent unless it is told to —
             * unlike the curl command, which always sends its own. ESPN's edge
             * answers 403 to a request with no User-Agent, and every live score
             * fetch this site ever made was refused: 717 fixtures, not one
             * score, ever. It went unnoticed until the season started, because
             * out of season there is nothing to fetch and "no scores" looks
             * exactly like "no games".
             *
             * 🚨 This is the true identity of the client, not a disguise. The
             * request IS libcurl, and this is the string curl itself would send
             * — measured on the box: `curl/8.5.0` and `python-requests/…` are
             * answered, while a browser string, an invented "Convoro/1.29.2"
             * and no header at all are all 403. Pretending to be Chrome would
             * be both a lie and a 403.
             */
            CURLOPT_USERAGENT => 'curl/' . (curl_version()['version'] ?? '8'),
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated
        // in 8.5, where the deprecation notice is what somebody gets served.

        if ($raw === false) {
            return [0, []];
        }

        $decoded = json_decode((string) $raw, true);

        return [$status, is_array($decoded) ? $decoded : []];
    }

    public function usable(): bool
    {
        return function_exists('curl_init');
    }
}
