<?php

namespace GuzzleHttp\Psr7;

// guzzlehttp/psr7 2.0 removed stream_for(). Keep the 1.x name working by
// forwarding it to Utils::streamFor(), which exists on 1.7+ and 2.x.
if (!function_exists('GuzzleHttp\\Psr7\\stream_for')) {
    function stream_for($resource = '', array $options = [])
    {
        return Utils::streamFor($resource, $options);
    }
}
