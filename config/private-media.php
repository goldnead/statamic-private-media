<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where private media lives
    |--------------------------------------------------------------------------
    |
    | A Statamic asset container. Every requested path is looked up in it, so
    | nothing outside the container can be served, whatever the URL says.
    |
    | Without a container, `disk` names a Laravel filesystem disk directly. The
    | path is then checked against the disk alone.
    |
    | Either way the disk must not be publicly reachable: a container with a
    | public URL hands the file to anyone who guesses the path, and this addon
    | never sees the request.
    |
    */

    'source' => [
        'container' => env('PRIVATE_MEDIA_CONTAINER', 'private'),
        'disk' => env('PRIVATE_MEDIA_DISK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery route
    |--------------------------------------------------------------------------
    |
    | GET /{prefix}/{resource}/{path} answers signed links made by
    | {{ private_media:url }} or PrivateMedia::url(). Off by default: a site
    | turns it on once the container and access rules are in place.
    |
    | `middleware` must include whatever puts the signed-in user on the request
    | (`web` for a session). `guard` picks the auth guard; null is the default.
    |
    */

    'routes' => [
        'enabled' => (bool) env('PRIVATE_MEDIA_ROUTES_ENABLED', false),
        'prefix' => env('PRIVATE_MEDIA_ROUTE_PREFIX', 'private-media'),
        'middleware' => ['web'],
        'guard' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed links
    |--------------------------------------------------------------------------
    |
    | How long a link from {{ private_media:url }} stays valid, in minutes. The
    | link is bound to the user it was made for; a forwarded link dies at the
    | recipient's first request, an expired one at anyone's. Pick a value
    | longer than your longest video: a player that seeks after expiry is
    | refused, and the page has to be reloaded for a fresh link.
    |
    */

    'links' => [
        'ttl' => (int) env('PRIVATE_MEDIA_LINK_TTL', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | How the bytes leave
    |--------------------------------------------------------------------------
    |
    | `strategy`:
    |   auto     local disks are streamed by this app, with byte ranges;
    |            disks that can make temporary URLs (S3 and friends) get a
    |            redirect to one; anything else is streamed.
    |   stream   always stream through this app.
    |   redirect always redirect; a disk that cannot make temporary URLs is
    |            refused with `storage_unavailable`.
    |
    | `temporary_url_ttl`: minutes the storage provider's URL stays valid
    | after the redirect. Past that the player gets its error from the
    | provider, not from here; a reload makes a new one.
    |
    */

    'delivery' => [
        'strategy' => env('PRIVATE_MEDIA_STRATEGY', 'auto'),
        'temporary_url_ttl' => (int) env('PRIVATE_MEDIA_TEMPORARY_URL_TTL', 60),
        'chunk_size' => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | Every refusal and every opening of a file is written to the table below.
    | A video player asks for a file in dozens of byte ranges; only the request
    | that starts at byte 0 is logged, unless `log_ranges` is on.
    |
    | `private-media:prune` deletes rows older than `retention_days`.
    |
    */

    'audit' => [
        'enabled' => true,
        'table' => 'private_media_access_log',
        'log_ranges' => false,
        'retention_days' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | statamic-entitlements
    |--------------------------------------------------------------------------
    |
    | With entitlements installed, the resource in the URL is the product slug
    | asked about. `subject_type` overrides the type grants are looked up
    | under; unset, it is the auth model's morph class on an eloquent user
    | install and `user` on a flat-file one.
    |
    */

    'entitlements' => [
        'subject_type' => env('PRIVATE_MEDIA_SUBJECT_TYPE'),
    ],

];
