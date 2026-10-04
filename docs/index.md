# omnilex/legifrance

## Installation

```sh
composer require omnilex/legifrance
```

```php
use Omnilex\Legifrance\LegifranceSourceFactory;

$legifrance = (new LegifranceSourceFactory($httpClient, $tokenStore))->create([
    'client_id' => $_ENV['PISTE_CLIENT_ID'],
    'client_secret' => $_ENV['PISTE_CLIENT_SECRET'],
    'sandbox' => false,
]);
```

The token store is optional (see the core's
[authentication](https://github.com/glitchr-studio/omnilex/blob/1.x/docs/authentication.md)):
without one, each PHP request asks PISTE for a new token.

## The documentation read

Read on 2026-10-04:

- the open data and API page of Légifrance: <https://www.legifrance.gouv.fr/contenu/pied-de-page/open-data-et-api>;
- "Utilisation de l'API Légifrance : exemples de cas pratique" (V3, 17/09/2025):
  <https://www.legifrance.gouv.fr/contenu/Media/Files/pied-de-page/exemples-d-utilisation-de-l-api.docx>;
- "Description des tris et filtres de l'API":
  <https://www.legifrance.gouv.fr/contenu/Media/Files/pied-de-page/description-des-tris-et-filtres-de-l-api.xlsx>;
- the lexicon: <https://www.legifrance.gouv.fr/contenu/Media/files/lexique-api-lgf.docx>;
- the Swagger of the API ("DILA - Légifrance", 1.6.2.5), as published by data.gouv.fr's record
  of the service (<https://www.data.gouv.fr/dataservices/legifrance/>):
  <https://raw.githubusercontent.com/betagouv/api_gouv_swaggers/main/swaggers/api-legifrance-v2.json>.

Base URLs: `https://api.piste.gouv.fr/dila/legifrance/lf-engine-app` (production),
`https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app` (sandbox). Token:
`https://oauth.piste.gouv.fr/api/oauth/token` (`sandbox-oauth.piste.gouv.fr` for the sandbox),
`grant_type=client_credentials`, `scope=openid`.

## Calls

| Method | Reads | API |
|---|---|---|
| `text()` | LEGITEXT, as in force on a day | `POST /consult/legiPart` `{textId, date}` |
| | JORFTEXT | `POST /consult/jorf` `{textCid}` |
| | NOR | `POST /consult/getJoWithNor` `{nor}` |
| | ELI (`/eli/decret/.../jo/texte`) | `POST /consult/eliAndAliasRedirectionTexte` `{idEliOrAlias}` |
| `article()` | LEGIARTI | `POST /consult/getArticle` `{id}` |
| | LEGITEXT + number | `POST /consult/getArticleWithIdAndNum` `{id, num}` |
| | ELI (`.../jo/article_1`) | `POST /consult/getArticleWithIdEliOrAlias` `{idEliOrAlias}` |
| `decision()` | JURITEXT (judicial courts) | `POST /consult/juri` `{textId}` |
| `citations()` | LEGIARTI | `POST /consult/relatedLinksArticle` `{articleId}`: `liensCite`, `liensCitePar` |
| | JURITEXT | the links `/consult/juri` gives |
| `search()` | | `POST /search` `{fond, recherche}` |
| `recent()` | texts | `POST /list/loda` `{publicationDate, natures, sort: PUBLICATION_DATE_DESC}` |
| | decisions (`kind: DECISION`) | `POST /search`, `DATE_DECISION` from the day to today, `DATE_DESC` |
| `request($path, $body)` | any other documented method | as given |

### An article as in force at a date

`/consult/getArticle` answers one version of an article with the list of all its versions
(`articleVersions`: identifier, start, end, legal state). `article($id, $number, $at)` picks the
version that covers the day and reads it (a second call when it is not the one first read).

- `article('LEGIARTI...')`: that very version.
- `article('LEGIARTI...', null, $at)`: the version of that article in force on `$at`.
- `article('LEGITEXT...', 'L36-11')`: the article of that number, in force today.
- `article('LEGITEXT...', 'L36-11', $at)`: in force on `$at`.

Null when the article did not exist that day, or when the API does not hold that version.

### Search

| `Query` | `fond` | Fields and filters |
|---|---|---|
| `kind: TEXT` (default) | `LODA_DATE` | `text` → `ALL`, `title` → `TITLE`, `number` → `NUM` (exact); `types` → `NATURE` (`LOI`, `ORDONNANCE`, `DECRET`, `ARRETE`...); `from`/`to` → `DATE_SIGNATURE`; `at` → `DATE_VERSION` (today by default) |
| `kind: ARTICLE` | `CODE_DATE` | `text` → `ARTICLE`, `title` → `TITLE`, `number` → `NUM_ARTICLE` (exact); `subjects` → `NOM_CODE` (the code's name: `Code civil`); `at` → `DATE_VERSION` |
| `kind: DECISION` | `JURI`, `CETAT` or `CONSTIT` by `jurisdictions`: `judiciaire` (default), `administratif`, `constitutionnel` | `text` → `ALL`, `title` → `TITLE`, `number` → `NUM_AFFAIRE` (`JURI`) or `NUM_DEC`; `types` → `CASSATION_NATURE_DECISION` (`JURI` only); `from`/`to` → `DATE_DECISION` |

Words are searched as `TOUS_LES_MOTS_DANS_UN_CHAMP`, an expression in double quotes as `EXACTE`.
`sort`: `PERTINENCE`; newest and oldest are `SIGNATURE_DATE_DESC` / `_ASC` for texts, `DATE_DESC`
/ `_ASC` for decisions. 100 results per page at most; `Results::$next` is the next page number.

A search for articles gives one `Reference` per article found (`Kind::ARTICLE`, its LEGIARTI as
`id`): pass it to `article()`.

## What is not supported, and why

| | Why |
|---|---|
| `decision()` for the administrative (CETATEXT) and constitutional (CONSTEXT) case law | the Swagger documents `/consult/juri` as reading "un texte de Jurisprudence judiciaire" (`JuriConsultRequest`, example `JURITEXT...`); nothing documents the reading of the other fonds. They are searched (`CETAT`, `CONSTIT`), and read on Légifrance by their `url`. For the administrative courts' decisions: `omnilex/justice-administrative` |
| `search()` of decisions over several orders of courts at once | each order is a `fond` of its own |
| `search()` without a text, a title or a number, outside the judicial case law | the documentation gives the "*" query for `JURI` only |
| `subjects` for the consolidated texts and for the case law; `types` outside `LODA` and `JURI` | no such filter in "Description des tris et filtres" |
| `from`/`to` for articles | articles are searched as in force on a day (`DATE_VERSION`) |
| `citations()` of a whole text | `/consult/relatedLinksArticle` reads an article; a text's own links come with `text()` (`Text::$citations`) |
| `recent()` by article, or with a text | `/list/loda` filters by nature and dates |
| Collective agreements (KALI), CNIL, circulars, company agreements, parliamentary debates, legislative files, the tables of the Journal officiel | documented and reachable with `request()`; not mapped to the models |

<a id="not-verified"></a>
## Not verified

No PISTE credentials were available: nothing was checked against the real API. In particular:

- **Dates in requests** are sent as `YYYY-MM-DD` strings, as the Swagger types them
  (`LegiConsultRequest.date`: `"2021-04-15"`; `FiltreDTO.singleDate`: `"2016-01-01"`). The
  examples document also shows milliseconds (`"date": 1561132975000`) for the same fields.
- **The shape of the search answers** (`results[].titles[]`, `sections[].extracts[]`) is read
  from the Swagger's `SearchResponseDTO`; which fields are filled for each `fond` and each
  `typePagination` is not documented.
- **Dates in answers** are read whether they come as milliseconds, as ISO strings or as days;
  `2999-01-01` is read as "no end".
- **The states** (`VIGUEUR`, `ABROGE_DIFF`, `MODIFIE`...) are mapped to `Status` from the
  Swagger's enumeration and the lexicon.
- **The quotas** are not public: the default throttle (two calls a second) is a guess at fair
  use, not a documented limit.
- The pages given as `url` (`/codes/article_lc/...`, `/loda/id/...`) follow Légifrance's
  public URLs; they are not returned by the API.

## Tests

`Tests/LegifranceSourceTest.php`, on `MockHttpClient`: each request is compared with the
documented example it implements (the article of a code at a date, a text by its number, the
judicial and administrative case law, `/consult/legiPart`, `/consult/getArticle`). The answers
are composed after the Swagger's definitions with its example values; texts marked "exemple"
are placeholders, not law.
