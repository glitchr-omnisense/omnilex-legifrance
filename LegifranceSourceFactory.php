<?php

namespace Omnilex\Legifrance;

use Omnilex\Auth\Piste;
use Omnilex\Config;
use Omnilex\Source\SourceFactory;
use Omnilex\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Légifrance, through PISTE: the application signs with its PISTE OAuth
 * credentials (client credentials).
 *
 *   options:
 *     client_id: '...'
 *     client_secret: '...'
 *     sandbox: false          # PISTE's sandbox, with the sandbox application's credentials
 *     throttle: 0.5           # seconds between two calls (the quotas are per application, on the portal)
 *     base_uri, token_uri     # to override either
 */
final class LegifranceSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilex.factory_name' => 'legifrance',
            'omnilex.required_options' => ['client_id', 'client_secret'],
            'sandbox' => false,
            'throttle' => 0.5,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $http = $this->http ?? HttpClient::create(['timeout' => 60]);
        $sandbox = $c->bool('sandbox');

        return new LegifranceSource(
            $http,
            Piste::credentials($http, (string) $c['client_id'], (string) $c['client_secret'], $sandbox, $this->tokens, 'legifrance', $c->get('token_uri')),
            (string) $c->get('base_uri', $sandbox ? LegifranceSource::SANDBOX_URI : LegifranceSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c['throttle'],
        );
    }
}
