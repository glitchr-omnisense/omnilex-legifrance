# omnilex/legifrance

Légifrance for [omnisense/omnilex](https://github.com/glitchr-omnisense/omnilex): codes, laws,
decrees and the Journal officiel through the DILA's API on PISTE - a text or one article **as
in force at a date**, a search in the codes, the consolidated texts and the case law, the links
of an article, the texts published since a date.

```php
$legifrance = (new LegifranceSourceFactory($http))->create(['client_id' => '...', 'client_secret' => '...']);

// Article L. 36-11 of the code des postes et des communications électroniques, on 1 January 2018
$article = $legifrance->article('LEGITEXT000006070987', 'L36-11', new DateTimeImmutable('2018-01-01'));
$article->content;
$article->version->from;                     // the day that wording started to apply
$article->versions;                          // every version of the article

$legifrance->search(new Query(number: 'L36-11', kind: Kind::ARTICLE, subjects: ['Code des postes et des communications électroniques'], at: '2018-01-01'));
$legifrance->text('LEGITEXT000038359719', new DateTimeImmutable('2019-06-22'));   // a law, as consolidated that day
$legifrance->recent(new DateTimeImmutable('-7 days'), new Query(types: ['LOI', 'DECRET']));
```

```yaml
omnilex:
    sources:
        legifrance: { factory: legifrance, options: { client_id: '%env(PISTE_CLIENT_ID)%', client_secret: '%env(PISTE_CLIENT_SECRET)%' } }
```

## Not verified against the real service

This package was written from the DILA's public documentation, without PISTE credentials:
**no call was made to the real API**. The requests follow the documented examples to the
letter; the reading of the answers follows the Swagger's definitions, which the documentation
does not illustrate with real answers. Expect adjustments at the first real run -
`.samples/live.php` of the workspace runs the documented cases as soon as `PISTE_CLIENT_ID` and
`PISTE_CLIENT_SECRET` are set. See [what is not verified](docs/index.md#verified-and-not).

## What you need to obtain

The API is free, behind [PISTE](https://piste.gouv.fr), the State's API portal.

1. Create an account: <https://piste.gouv.fr/registration>.
2. Accept the terms of use of the **Légifrance** API (menu "API" > "Consentement CGU API"), for
   the sandbox and for production.
3. In "Applications", open the application (a sandbox one is created with the account; create
   one for production) and tick the **Légifrance** API in its list of APIs.
4. Copy the application's OAuth credentials: **Client ID** and **Client Secret**.

| Option | |
|---|---|
| `client_id`, `client_secret` | the application's OAuth credentials (required) |
| `sandbox` | `true` with the sandbox application's credentials: `sandbox-api.piste.gouv.fr`, data that differ from production |
| `throttle` | seconds between two calls (0.5) |
| `base_uri`, `token_uri` | to override either |

The data are under the [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence/);
their use is subject to the terms of PISTE and of the Légifrance API, and to quotas "detailed on
the PISTE portal" - not public: a quota spent comes back as a `RateLimitedException`.

See [docs/](docs/index.md).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the DILA (Légifrance) or the PISTE platform. It reaches their service on behalf of
whoever runs it, and it does not redistribute their data beyond the short excerpts recorded as test
fixtures. Its MIT licence covers this package's code only and grants no right to the service or to
its data.

Whoever installs and runs this package is responsible for complying with the terms of PISTE and of
the Légifrance API ([piste.gouv.fr/cgu](https://piste.gouv.fr/cgu)), for obtaining whatever they
require (an account, a key, a licence or subscription, an attribution or a citation, a rate limit to
respect), and for any use made of what it returns. The authors publish it free of charge, make no
commercial use of it, and accept no liability for how it is used (see [LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
