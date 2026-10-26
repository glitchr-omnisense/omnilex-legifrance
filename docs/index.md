# omnilex/legifrance

Légifrance, the DILA's base of French law - codes, laws, ordinances, decrees and orders as
consolidated and as published in the Journal officiel, and the case law - for
[omnisense/omnilex](https://github.com/glitchr-omnisense/omnilex), through its API on PISTE, the
French State's API portal. The source calls:

| Operation | Request |
|---|---|
| `search()` | `POST /search` `{fond, recherche}` |
| `text()` | `POST /consult/legiPart`, `/consult/jorf`, `/consult/getJoWithNor`, `/consult/eliAndAliasRedirectionTexte` |
| `article()` | `POST /consult/getArticle`, `/consult/getArticleWithIdAndNum`, `/consult/getArticleWithIdEliOrAlias` |
| `decision()` | `POST /consult/juri` |
| `citations()` | `POST /consult/relatedLinksArticle`, or the links of `/consult/juri` |
| `recent()` | `POST /list/loda`, or `/search` for decisions |
| `request($path, $body)` | any other documented method |
| token | `POST https://oauth.piste.gouv.fr/api/oauth/token`, `grant_type=client_credentials`, `scope=openid` |

on `https://api.piste.gouv.fr/dila/legifrance/lf-engine-app` (`LegifranceSource::BASE_URI`), or
`https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app` and
`https://sandbox-oauth.piste.gouv.fr/api/oauth/token` with `sandbox: true`.

Documentation read on 2026-10-04: the open data and API page of Légifrance
(<https://www.legifrance.gouv.fr/contenu/pied-de-page/open-data-et-api>); "Utilisation de l'API
Légifrance : exemples de cas pratique" (V3, 17/09/2025,
<https://www.legifrance.gouv.fr/contenu/Media/Files/pied-de-page/exemples-d-utilisation-de-l-api.docx>);
"Description des tris et filtres de l'API"
(<https://www.legifrance.gouv.fr/contenu/Media/Files/pied-de-page/description-des-tris-et-filtres-de-l-api.xlsx>);
the lexicon (<https://www.legifrance.gouv.fr/contenu/Media/files/lexique-api-lgf.docx>); the
Swagger of the API ("DILA - Légifrance", 1.6.2.5) as published by data.gouv.fr's record of the
service (<https://www.data.gouv.fr/dataservices/legifrance/>,
<https://raw.githubusercontent.com/betagouv/api_gouv_swaggers/main/swaggers/api-legifrance-v2.json>).

## Installation

```sh
composer require omnilex/legifrance
```

Requires PHP 8.2, `omnisense/omnilex` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `OmnilexBundle`, the factory is registered as soon as the package is installed,
with the application's HTTP client and the token store over `cache.app`.

## Credentials

An account on <https://piste.gouv.fr> (free), an application there with the Légifrance API
subscribed and its terms of use accepted, and the application's **OAuth client id and secret**.
The sandbox has its own application and credentials. The token is asked on the first call, kept
until a minute before it dies, in the token store when the factory has one (without one, each PHP
request asks PISTE for a new token), and asked afresh after a 401
([the core's authentication](https://github.com/glitchr-omnisense/omnilex/blob/1.x/docs/authentication.md)).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | |
| `client_secret` | string | - | yes | |
| `sandbox` | bool | `false` | no | PISTE's sandbox, with the sandbox application's credentials |
| `throttle` | float | `0.5` | no | seconds between two calls: a guess at fair use, the quotas are not public |
| `base_uri` | string | production's or the sandbox's | no | |
| `token_uri` | string | PISTE's or the sandbox's | no | |
| `user_agent` | string | `omnilex/1.x (+https://github.com/glitchr-omnisense/omnilex)` | no | |

A missing credential is an `InvalidConfigException` when the source is built (`Registry::usable()`
leaves it out). Without an HTTP client, the factory makes one with a 60-second timeout.

> [!WARNING]
> Known defect: `sandbox` is read with PHP's `(bool)`: `sandbox: 'false'` builds a source on the
> sandbox (seen on 2026-10-26). Give a real boolean (`%env(bool:PISTE_SANDBOX)%`).

## Capabilities

`capabilities()`: holds texts, articles and decisions; reads LEGITEXT, LEGIARTI, JORFTEXT, NOR,
ELI and JURITEXT; every `Query` criterion (`text`, `title`, `number`, `kind`, `jurisdictions`,
`from`, `to`, `subjects`, `types`, `at`), each refused where the fonds does not take it (below);
jurisdictions `FR`; versions yes; 100 per page.

| Operation | Supported | Reads |
|---|---|---|
| `search()` | yes | |
| `text()` | yes, consolidated at a date or as published | LEGITEXT; JORFTEXT, NOR, French ELI (`/eli/decret/.../jo/texte`) |
| `article()` | yes, as in force at a date | LEGIARTI; LEGITEXT and a number; French ELI (`.../jo/article_1`) |
| `decision()` | judicial courts only | JURITEXT |
| `citations()` | yes | LEGIARTI (an article), JURITEXT (a judicial decision) |
| `recent()` | texts published, or decisions | |

Beyond the contract: `request(string $path, array $body = []): ?array`, any method of the API as
its Swagger describes it (`$legifrance->request('consult/kaliText', ['id' =>
'KALITEXT000005677408'])`), `null` on a 404.

## Behaviour

**A text.** `text($id, $at)`:

| Identifier | Request | The day |
|---|---|---|
| LEGITEXT | `/consult/legiPart` `{textId, date}` | read: the text as consolidated that day (today by default) |
| JORFTEXT | `/consult/jorf` `{textCid}` | not read: the Journal officiel publishes one state |
| NOR | `/consult/getJoWithNor` `{nor}` | not read |
| French ELI | `/consult/eliAndAliasRedirectionTexte` `{idEliOrAlias}` (the ELI's path) | not read |

The `Text` holds its articles in order (each with its version's status, its headings as `path`,
its nota), its whole content as lines (`Article <n>`, the article, the section titles), the
LEGITEXT/JORFTEXT, NOR and ELI identifiers, the nature as `type`, `publishedOn`, one version (its
start, end and state), the key words as `subjects`, the links as `citations`, and as `url` the
page of the consolidated text that day (`/codes/texte_lc/<id>/<date>` for a code, `/loda/id/...`
otherwise) or of the Journal officiel's text. `date` is `null`. An answer without a title, an
identifier, sections or articles is `null`. A whole code comes in one answer: read its articles
one by one.

**An article.** `/consult/getArticle` answers one version of an article with the list of all its
versions (`articleVersions`: identifier, start, end, legal state). `article($id, $number, $at)`:

- `article('LEGIARTI...')`: that very version;
- `article('LEGIARTI...', null, $at)`: the version of that article in force on `$at` (a second
  call when it is not the one first read);
- `article('LEGITEXT...', 'L36-11')`: `/consult/getArticleWithIdAndNum` `{id, num}`, the version
  in force today;
- `article('LEGITEXT...', 'L36-11', $at)`: in force on `$at`;
- a French ELI: `/consult/getArticleWithIdEliOrAlias`, the article as published.

`null` when the article did not exist that day, or when the API does not hold that version. A
number with an article's own identifier, or a LEGITEXT without a number, is a
`NotSupportedException`. The `Article` holds its number, its text (`texte`, else its HTML as
plain text), its HTML, its version and every version oldest first, its text's identifier and
title, its headings (`fullSectionsTitre`) as `path`, its nota, its links (modifications and
citations) and its page (`/codes/article_lc/<id>` or `/loda/article_lc/<id>`).

**States.** Légifrance's states map to `Status`: `VIGUEUR`, `VIGUEUR_ETEN`, `VIGUEUR_NON_ETEN` and
`ABROGE_DIFF` (in force, an end set) → `IN_FORCE`; `VIGUEUR_DIFF` → `FUTURE`; `ABROGE` →
`REPEALED`; `ANNULE` → `ANNULLED`; `MODIFIE`, `MODIFIE_MORT_NE` → `MODIFIED`; `PERIME`,
`TRANSFERE`, `DISJOINT`, `SUBSTITUE`, `REMPLACE`, `DENONCE` → `EXPIRED`; any other → `UNKNOWN`.
Dates are read whether they come as milliseconds (in Paris time), ISO strings or days;
`2999-01-01` is "no end".

**Search.**

| `Query` | `fond` | Fields and filters |
|---|---|---|
| `kind: TEXT` (default) | `LODA_DATE` | `text` → `ALL`, `title` → `TITLE`, `number` → `NUM` (exact); `types` → `NATURE` (`LOI`, `ORDONNANCE`, `DECRET`, `ARRETE`...); `from`/`to` → `DATE_SIGNATURE`; `at` → `DATE_VERSION` (today by default) |
| `kind: ARTICLE` | `CODE_DATE` | `text` → `ARTICLE`, `title` → `TITLE`, `number` → `NUM_ARTICLE` (exact, `typePagination: ARTICLE`); `subjects` → `NOM_CODE` (the code's name: `Code civil`); `at` → `DATE_VERSION` |
| `kind: DECISION` | `JURI`, `CETAT` or `CONSTIT` by `jurisdictions`: `judiciaire` (default), `administratif`, `constitutionnel` (or `juri`, `cetat`, `constit`) | `text` → `ALL`, `title` → `TITLE`, `number` → `NUM_AFFAIRE` (`JURI`) or `NUM_DEC`; `types` → `CASSATION_NATURE_DECISION` (`JURI` only); `from`/`to` → `DATE_DECISION` |

Words are searched as `TOUS_LES_MOTS_DANS_UN_CHAMP`, an expression in double quotes as `EXACTE`,
the fields joined by `ET`. `sort`: `PERTINENCE`; `newest`/`oldest` are `SIGNATURE_DATE_DESC`/`_ASC`
for texts, `DATE_DESC`/`_ASC` for decisions, `PERTINENCE` for articles. `limit` 1 to 100;
`Results::$next` is the next page number while `page * limit` is below `totalResultNumber`. A
search for articles gives one `Reference` per article found (`Kind::ARTICLE`, its LEGIARTI as
`id`): pass it to `article()`. A text or a decision found has its page as `url`.

Refused (`NotSupportedException`): several orders of courts at once; an order other than the
three; `types` outside `LODA` and `JURI`; `subjects` for texts and decisions; `at` for decisions;
`jurisdictions` for texts and articles; `from`/`to` for articles; a search with no text, title or
number outside `JURI` (where `*` is sent, as the API's examples do).

**What is new.** `recent($since)` lists the texts published from that day to today: `/list/loda`
with `publicationDate`, `natures` (the query's `types`), `PUBLICATION_DATE_DESC`, pages of 1 to
100. With `kind: DECISION`, a search of the decisions from that day to today, newest first.
`kind: ARTICLE`, and any criterion but `kind` and `types` for texts, are refused.

**A decision.** `/consult/juri` `{textId}` reads a decision of the judicial courts: its title, the
JURITEXT, ECLI and pourvoi identifiers, the court (`juridiction`, its formation, the seat of
appeal), `date`, the case numbers, `type`, `solution`, `publication`, the summaries joined as
`summary` and their abstracts as `subjects`, the text and its HTML, its links, `updatedOn`, `url`
its Légifrance page. `pseudonymised` is `null`: the API does not say. Administrative (CETATEXT)
and constitutional (CONSTEXT) decisions are searched, not read: the Swagger documents
`/consult/juri` for the judicial case law only; read them on Légifrance by their `url`, or the
administrative courts' decisions with `omnilex/justice-administrative`.

**Links.** `citations('LEGIARTI...')`: `/consult/relatedLinksArticle`, `liensCite` (`CITES`) and
`liensCitePar` (`CITED_BY`), at most `$limit` each. `citations('JURITEXT...')`: the decision's
links. A text's or a decision's links are typed by Légifrance (`CITATION`, `MODIFIE`, `ABROGE`,
`CREE`...) and oriented (`source`, `cible`): `CITES`/`CITED_BY`, `REPEALS`/`REPEALED_BY`,
`AMENDS`/`AMENDED_BY` for the others; the type kept as the note.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilex\Legifrance\LegifranceSourceFactory;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Symfony\Component\HttpClient\HttpClient;

$legifrance = (new LegifranceSourceFactory(HttpClient::create()))->create([
    'client_id' => getenv('PISTE_CLIENT_ID'),
    'client_secret' => getenv('PISTE_CLIENT_SECRET'),
]);

// Article L36-11 of the Code des postes et des communications électroniques, as in force on 1 January 2018
$article = $legifrance->article('LEGITEXT000006070987', 'L36-11', new DateTimeImmutable('2018-01-01'));
echo $article->textTitle, ', art. ', $article->number, ': ', $article->version->status->value, ' from ', $article->version->from?->format('Y-m-d'), "\n";
foreach ($article->versions as $version) {
    echo '  ', $version->id, ' ', $version->from?->format('Y-m-d'), ' -> ', $version->to?->format('Y-m-d') ?? '...', ' ', $version->status->value, "\n";
}

$found = $legifrance->search(new Query(text: 'responsabilité', kind: Kind::ARTICLE, subjects: ['Code civil'], limit: 5));
foreach ($found as $reference) {
    echo $reference->title, ' ', $reference->id, "\n";
}

foreach ($legifrance->recent(new DateTimeImmutable('-7 days'), new Query(types: ['DECRET'])) as $text) {
    echo $text->date?->format('Y-m-d'), ' ', $text->title, "\n";
}
```

Not run: no PISTE credentials. In Symfony:

```yaml
omnilex:
    sources:
        legifrance:
            factory: legifrance
            options:
                client_id: '%env(default::PISTE_CLIENT_ID)%'
                client_secret: '%env(default::PISTE_CLIENT_SECRET)%'
                sandbox: '%env(bool:default::PISTE_SANDBOX)%'
```

From the core's harness, with the credentials in `docker/.env`:
`docker compose run --rm omnilex article legifrance LEGITEXT000006070987 L36-11 --at 2018-01-01`.

## Errors

| Case | Result |
|---|---|
| unknown (404, or an empty answer); no version that day | `null` (`citations()`: `[]`) |
| an identifier it does not read (a CETATEXT for `decision()`, a CELEX, a European ELI) | `NotSupportedException` |
| a criterion refused (above), an article without its number, a number with a LEGIARTI | `NotSupportedException` |
| credentials refused (the token server, a 401 twice, a 403) | `AuthenticationException` |
| a 429, the application's quota spent | `RateLimitedException` |
| a 5xx, a 423, a timeout | `UnavailableException` |
| another 4xx | `ProviderException` |
| a credential missing | `InvalidConfigException`, when the source is built |

## Verified, and not

Tested offline: `Tests/LegifranceSourceTest.php` (18 tests), on `MockHttpClient`: each request
compared with the documented example it implements (the article of a code at a date, a text by
its number, the judicial and administrative case law, `/consult/legiPart`, `/consult/getArticle`);
the search's refusals; an article by its identifier, at a date, by its number, by its ELI; a text
consolidated at a date and of the Journal officiel by identifier, NOR or ELI; a judicial decision;
an article's links; the texts published since a date; `request()`; credentials needed and a
refusal not an answer. The answers are composed after the Swagger's definitions with its example
values; texts marked "exemple" are placeholders, not law.

Not verified: **no call was made to Légifrance** - no PISTE credentials. In particular:

- dates in requests are sent as `YYYY-MM-DD` strings, as the Swagger types them
  (`LegiConsultRequest.date`: `"2021-04-15"`; `FiltreDTO.singleDate`: `"2016-01-01"`); the
  examples document also shows milliseconds (`"date": 1561132975000`) for the same fields;
- the shape of the search answers (`results[].titles[]`, `sections[].extracts[]`) is read from the
  Swagger's `SearchResponseDTO`; which fields are filled for each `fond` and each `typePagination`
  is not documented;
- the states are mapped from the Swagger's enumeration and the lexicon;
- the quotas are not public: the default throttle (two calls a second) is a guess at fair use;
- the pages given as `url` (`/codes/article_lc/...`, `/loda/id/...`) follow Légifrance's public
  addresses; the API does not return them.

Collective agreements (KALI), CNIL, circulars, company agreements, parliamentary debates,
legislative files and the tables of the Journal officiel are documented and reachable with
`request()`; they are not mapped to the models.

## Responsibility

An independent, unofficial client, not affiliated with the DILA (Légifrance) or the PISTE platform.
Consolidated texts are documentary: only the Journal officiel is authentic. Whoever runs it
complies with the terms of PISTE and of the Légifrance API
([piste.gouv.fr/cgu](https://piste.gouv.fr/cgu)): see the README's Responsibility section.
