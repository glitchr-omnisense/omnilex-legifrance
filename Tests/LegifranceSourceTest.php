<?php

namespace Omnilex\Legifrance\Tests;

use Omnilex\Auth\Piste;
use Omnilex\Exception\AuthenticationException;
use Omnilex\Exception\InvalidConfigException;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Legifrance\LegifranceSource;
use Omnilex\Legifrance\LegifranceSourceFactory;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Citation;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Relation;
use Omnilex\Model\Scheme;
use Omnilex\Model\Status;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * No PISTE credentials were at hand: nothing here was checked against the
 * real service.
 *
 * The requests are checked against the DILA's own documentation, read on
 * 2026-10-04: "Utilisation de l'API Légifrance : exemples de cas pratique"
 * (V3, 17/09/2025), "Description des tris et filtres de l'API" and the
 * Swagger of the API (DILA - Légifrance, 1.6.2.5).
 *
 * That documentation gives requests, not answers. The fixtures are composed
 * after the Swagger's definitions (SearchResponseDTO, GetArticleResponse,
 * ConsultTextResponse, ConsultJuriTextResponse, RelatedLinksArticleResponse,
 * LODAListResponse) with its example values and the identifiers of the
 * documented cases; the other identifiers and every text marked "exemple"
 * are placeholders, not law.
 */
final class LegifranceSourceTest extends TestCase
{
    /** @var list<array{path: string, body: array<string, mixed>, headers: array<string, string>}> */
    private array $calls = [];

    private function source(array $options = []): LegifranceSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if (str_ends_with($path, '/api/oauth/token')) {
                $this->calls[] = ['path' => $url, 'body' => [], 'headers' => []];

                return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/token.json'));
            }
            $headers = [];
            foreach ($options['normalized_headers'] as $name => $values) {
                $headers[$name] = substr($values[0], \strlen($name) + 2);
            }
            $body = json_decode((string) $options['body'], true) ?? [];
            $path = substr($path, \strlen('/dila/legifrance/lf-engine-app/'));
            $this->calls[] = ['path' => $path, 'body' => $body, 'headers' => $headers + ['method' => $method, 'host' => (string) parse_url($url, \PHP_URL_HOST)]];
            $fixture = match ($path) {
                'search' => match ($body['fond']) {
                    'CODE_DATE' => 'search-code-article.json',
                    'JURI', 'CETAT', 'CONSTIT' => 'search-juri.json',
                    default => 'search-loda.json',
                },
                'consult/getArticle' => is_file(__DIR__.'/Fixtures/article-'.$body['id'].'.json') ? 'article-'.$body['id'].'.json' : null,
                'consult/getArticleWithIdAndNum' => 'L36-11' === $body['num'] ? 'article-LEGIARTI000037826134.json' : null,
                'consult/legiPart', 'consult/jorf', 'consult/getJoWithNor', 'consult/eliAndAliasRedirectionTexte' => 'legipart.json',
                'consult/juri' => 'JURITEXT000037999394' === $body['textId'] ? 'juri.json' : null,
                'consult/relatedLinksArticle' => 'related-links.json',
                'list/loda' => 'list-loda.json',
                default => null,
            };

            return null === $fixture ? new MockResponse('', ['http_code' => 404]) : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new LegifranceSourceFactory($http))->create($options + ['client_id' => 'my-client', 'client_secret' => 'my-secret', 'throttle' => 0]);
    }

    public function testWhatItDoes(): void
    {
        $source = $this->source();

        self::assertSame('legifrance', $source->getName());
        self::assertSame(['search', 'text', 'article', 'decision', 'citations', 'recent'], Capabilities::operations($source));
        $capabilities = $source->capabilities();
        self::assertTrue($capabilities->versions);
        self::assertTrue($capabilities->reads(Scheme::LEGIARTI));
        self::assertTrue($capabilities->reads(Scheme::NOR));
        self::assertFalse($capabilities->reads(Scheme::CELEX));
        self::assertSame(100, $capabilities->pageSize);
        self::assertFalse($capabilities->pseudonymised);
    }

    /** "Récupérer un article en vigueur à une date donnée, d'un code dont on connaît l'id", step 1. */
    public function testAnArticleOfACodeSearchedByItsNumberAtADate(): void
    {
        $results = $this->source()->search(new Query(number: 'L36-11', kind: Kind::ARTICLE, subjects: ['Code des postes et des communications électroniques'], at: '2018-01-01', limit: 10));

        self::assertSame(Piste::TOKEN_URI, $this->calls[0]['path'], 'the token first');
        $call = $this->calls[1];
        self::assertSame(['search', 'POST', 'api.piste.gouv.fr'], [$call['path'], $call['headers']['method'], $call['headers']['host']]);
        self::assertSame('Bearer th2uv3lq9zY2vAoth59QpYtCSID1iWn0AG6XhnjgAP54eoY1440vp3', $call['headers']['authorization']);
        self::assertSame('application/json', $call['headers']['content-type']);
        self::assertSame('application/json', $call['headers']['accept']);
        self::assertSame([
            'fond' => 'CODE_DATE',
            'recherche' => [
                'champs' => [
                    ['typeChamp' => 'NUM_ARTICLE', 'criteres' => [['typeRecherche' => 'EXACTE', 'valeur' => 'L36-11', 'operateur' => 'ET']], 'operateur' => 'ET'],
                ],
                'filtres' => [
                    ['facette' => 'NOM_CODE', 'valeurs' => ['Code des postes et des communications électroniques']],
                    ['facette' => 'DATE_VERSION', 'singleDate' => '2018-01-01'],
                ],
                'pageNumber' => 1,
                'pageSize' => 10,
                'operateur' => 'ET',
                'sort' => 'PERTINENCE',
                'typePagination' => 'ARTICLE',
            ],
        ], $call['body'], 'the request of the documented example, the date as the Swagger writes it');

        self::assertSame(1, $results->total);
        self::assertNull($results->next);
        $article = $results->items[0];
        self::assertSame(Kind::ARTICLE, $article->kind);
        self::assertSame('LEGIARTI000033219357', $article->id, 'the identifier the documented example reads next');
        self::assertSame('Code des postes et des communications électroniques - art. L36-11', $article->title);
        self::assertSame('L36-11', $article->number);
        self::assertSame(Status::IN_FORCE, $article->status);
        self::assertSame(['legiarti:LEGIARTI000033219357', 'legitext:LEGITEXT000006070987'], $article->identifiers->keys());
        self::assertSame('https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000033219357', $article->url);
    }

    /** "Recherche simple sur un mot dans un code", by date of version. */
    public function testWordsInTheArticlesOfACode(): void
    {
        $this->source()->search(new Query(text: 'responsabilite', kind: Kind::ARTICLE, subjects: ['Code civil'], at: '2025-04-15'));

        $search = end($this->calls)['body'];
        self::assertSame('CODE_DATE', $search['fond']);
        self::assertSame([['typeChamp' => 'ARTICLE', 'criteres' => [['typeRecherche' => 'TOUS_LES_MOTS_DANS_UN_CHAMP', 'valeur' => 'responsabilite', 'operateur' => 'ET']], 'operateur' => 'ET']], $search['recherche']['champs']);
        self::assertSame([['facette' => 'NOM_CODE', 'valeurs' => ['Code civil']], ['facette' => 'DATE_VERSION', 'singleDate' => '2025-04-15']], $search['recherche']['filtres']);
        self::assertSame('DEFAUT', $search['recherche']['typePagination']);
        self::assertSame(25, $search['recherche']['pageSize']);
    }

    /** "Récupérer un article en vigueur à une date donnée, d'un texte numéroté", step 1. */
    public function testAConsolidatedTextSearchedByItsNumber(): void
    {
        $results = $this->source()->search(new Query(number: '2019-290', types: ['loi'], from: '2019-01-01', to: '2019-12-31', at: '2019-06-22', sort: Query::NEWEST, cursor: '2', limit: 10));

        $search = end($this->calls)['body'];
        self::assertSame('LODA_DATE', $search['fond']);
        self::assertSame([['typeChamp' => 'NUM', 'criteres' => [['typeRecherche' => 'EXACTE', 'valeur' => '2019-290', 'operateur' => 'ET']], 'operateur' => 'ET']], $search['recherche']['champs']);
        self::assertSame([
            ['facette' => 'NATURE', 'valeurs' => ['LOI']],
            ['facette' => 'DATE_SIGNATURE', 'dates' => ['start' => '2019-01-01', 'end' => '2019-12-31']],
            ['facette' => 'DATE_VERSION', 'singleDate' => '2019-06-22'],
        ], $search['recherche']['filtres']);
        self::assertSame(['SIGNATURE_DATE_DESC', 2, 'DEFAUT'], [$search['recherche']['sort'], $search['recherche']['pageNumber'], $search['recherche']['typePagination']]);

        self::assertSame(135, $results->total);
        self::assertSame('3', $results->next);
        self::assertCount(1, $results, 'a result without a title is left out');
        $law = $results->items[0];
        self::assertSame(Kind::TEXT, $law->kind);
        self::assertSame('LEGITEXT000038359719', $law->id, 'without the date its search identifier carries');
        self::assertSame(['legitext:LEGITEXT000038359719', 'jorftext:JORFTEXT000038358582', 'nor:JUSX1904815L'], $law->identifiers->keys());
        self::assertSame(['LOI', '2019-290', Status::IN_FORCE], [$law->type, $law->number, $law->status]);
        self::assertSame('2019-04-10', $law->date->format('Y-m-d'));
    }

    /** "Recherche dans la jurisprudence judiciaire" and "administrative". */
    public function testTheCaseLawByOrderOfCourts(): void
    {
        $source = $this->source();
        $results = $source->search(new Query(kind: Kind::DECISION, types: ['arret'], from: '2025-01-01', to: '2025-01-31', limit: 10));

        $search = end($this->calls)['body'];
        self::assertSame('JURI', $search['fond']);
        self::assertSame([['typeChamp' => 'ALL', 'criteres' => [['typeRecherche' => 'UN_DES_MOTS', 'valeur' => '*', 'operateur' => 'ET']], 'operateur' => 'ET']], $search['recherche']['champs'], 'every decision of the period: "*", as the example does');
        self::assertSame([
            ['facette' => 'CASSATION_NATURE_DECISION', 'valeurs' => ['ARRET']],
            ['facette' => 'DATE_DECISION', 'dates' => ['start' => '2025-01-01', 'end' => '2025-01-31']],
        ], $search['recherche']['filtres']);
        self::assertSame(Kind::DECISION, $results->items[0]->kind);
        self::assertSame('JURITEXT000037999394', $results->items[0]->id);
        self::assertSame('https://www.legifrance.gouv.fr/juri/id/JURITEXT000037999394', $results->items[0]->url);

        $source->search(new Query(number: '17/030701', kind: Kind::DECISION, sort: Query::NEWEST));
        $search = end($this->calls)['body'];
        self::assertSame(['NUM_AFFAIRE', 'EXACTE', '17/030701', 'DATE_DESC'], [$search['recherche']['champs'][0]['typeChamp'], $search['recherche']['champs'][0]['criteres'][0]['typeRecherche'], $search['recherche']['champs'][0]['criteres'][0]['valeur'], $search['recherche']['sort']]);

        $source->search(new Query(text: 'CESEDA', kind: Kind::DECISION, jurisdictions: ['administratif'], from: '2000-01-01', to: '2005-01-01', limit: 500));
        $search = end($this->calls)['body'];
        self::assertSame(['CETAT', 100], [$search['fond'], $search['recherche']['pageSize']]);
        self::assertSame([['facette' => 'DATE_DECISION', 'dates' => ['start' => '2000-01-01', 'end' => '2005-01-01']]], $search['recherche']['filtres']);
    }

    public function testWhatASearchCannotDoIsRefused(): void
    {
        $source = $this->source();
        $refused = [
            new Query(text: 'bail', kind: Kind::DECISION, jurisdictions: ['judiciaire', 'administratif']),
            new Query(text: 'bail', kind: Kind::DECISION, jurisdictions: ['cc']),
            new Query(text: 'bail', kind: Kind::DECISION, jurisdictions: ['administratif'], types: ['ARRET']),
            new Query(text: 'bail', kind: Kind::DECISION, subjects: ['Bail']),
            new Query(text: 'bail', kind: Kind::DECISION, at: '2020-01-01'),
            new Query(text: 'bail', jurisdictions: ['judiciaire']),
            new Query(text: 'bail', kind: Kind::ARTICLE, types: ['LOI']),
            new Query(text: 'bail', kind: Kind::ARTICLE, from: '2020-01-01'),
            new Query(text: 'bail', subjects: ['Code civil']),
            new Query(types: ['LOI']),
            new Query(kind: Kind::DECISION, jurisdictions: ['administratif']),
        ];
        foreach ($refused as $query) {
            try {
                $source->search($query);
                self::fail('Not refused: '.implode(', ', $query->criteria()));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls, 'refused before any call, the token included');
    }

    /** "Étape 2 : Récupérer le contenu de l'article avec la méthode POST /consult/getArticle". */
    public function testAnArticleByItsIdentifierIsThatVersion(): void
    {
        $article = $this->source()->article('LEGIARTI000033219357');

        self::assertSame(['consult/getArticle', ['id' => 'LEGIARTI000033219357']], [$this->calls[1]['path'], $this->calls[1]['body']]);
        self::assertCount(2, $this->calls);
        self::assertSame('LEGIARTI000033219357', $article->id);
        self::assertSame('L36-11', $article->number);
        self::assertSame('(texte de l\'article L36-11, version du 9 octobre 2016 - exemple)', $article->content);
        self::assertSame(['2016-10-09', '2018-11-26', Status::MODIFIED, '9.0'], [$article->version->from->format('Y-m-d'), $article->version->to->format('Y-m-d'), $article->version->status, $article->version->label]);
        self::assertFalse($article->isInForce());
        self::assertSame(['LEGIARTI000006465857', 'LEGIARTI000024506177', 'LEGIARTI000033219357', 'LEGIARTI000037826134'], array_map(static fn ($v) => $v->id, $article->versions), 'every version, oldest first');
        self::assertNull($article->versions[3]->to, '2999-01-01 stands for no end');
        self::assertSame('LEGITEXT000006070987', $article->textId);
        self::assertSame('Code des postes et des communications électroniques', $article->textTitle);
        self::assertStringStartsWith('Partie législative > LIVRE II', $article->path);
        self::assertSame(['legiarti:LEGIARTI000033219357', 'legiarti:LEGIARTI000006465857'], $article->identifiers->keys(), 'itself, and the identifier common to its versions');
        self::assertSame('https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000033219357', $article->url);

        self::assertSame([Relation::AMENDED_BY, Relation::CITES], array_map(static fn (Citation $c) => $c->relation, $article->citations));
        self::assertSame(['LEGIARTI000033205142', 'LOI', '53', '2016-10-07'], [$article->citations[0]->target->id, $article->citations[0]->target->type, $article->citations[0]->target->number, $article->citations[0]->target->date->format('Y-m-d')]);
    }

    public function testAnArticleAsInForceAtADate(): void
    {
        $source = $this->source();

        // The version asked by its identifier is the one of that day: one call.
        $article = $source->article('LEGIARTI000033219357', null, new \DateTimeImmutable('2018-01-01'));
        self::assertSame('LEGIARTI000033219357', $article->id);
        self::assertCount(2, $this->calls);

        // Another day, another version: read in turn.
        $article = $source->article('LEGIARTI000033219357', null, new \DateTimeImmutable('2020-06-01'));
        self::assertSame(['consult/getArticle', ['id' => 'LEGIARTI000037826134']], [end($this->calls)['path'], end($this->calls)['body']]);
        self::assertSame('LEGIARTI000037826134', $article->id);
        self::assertSame('(texte de l\'article L36-11, version en vigueur - exemple)', $article->content);
        self::assertTrue($article->isInForce());
        self::assertSame('2018-11-26', $article->version->from->format('Y-m-d'));
        self::assertNull($article->version->to);

        // The day a version ends is the day the next one starts: the next one applies.
        self::assertSame('LEGIARTI000037826134', $source->article('LEGIARTI000033219357', null, new \DateTimeImmutable('2018-11-26'))->id);

        // Before the article existed: nothing.
        self::assertNull($source->article('LEGIARTI000033219357', null, new \DateTimeImmutable('2000-01-01')));
        // A version whose text the API does not hold.
        self::assertNull($source->article('LEGIARTI000033219357', null, new \DateTimeImmutable('2012-01-01')));
    }

    public function testAnArticleOfACodeByItsNumber(): void
    {
        $source = $this->source();
        $article = $source->article('LEGITEXT000006070987', 'L36-11');

        self::assertSame(['consult/getArticleWithIdAndNum', ['id' => 'LEGITEXT000006070987', 'num' => 'L36-11']], [$this->calls[1]['path'], $this->calls[1]['body']]);
        self::assertSame('LEGIARTI000037826134', $article->id, 'in force today');

        $article = $source->article('LEGITEXT000006070987', 'L36-11', new \DateTimeImmutable('2017-03-01'));
        self::assertSame('LEGIARTI000033219357', $article->id, 'the version of 2017, read from the list of versions');

        self::assertNull($source->article('LEGITEXT000006070987', 'L99-99'));
    }

    public function testAnArticleOfTheJournalOfficielByItsEli(): void
    {
        self::assertNull($this->source()->article('/eli/decret/2021/7/13/PRMD2117108D/jo/article_1'));
        self::assertSame(['consult/getArticleWithIdEliOrAlias', ['idEliOrAlias' => '/eli/decret/2021/7/13/PRMD2117108D/jo/article_1']], [$this->calls[1]['path'], $this->calls[1]['body']]);
    }

    public function testAnArticleNeedsAnIdentifierItReads(): void
    {
        foreach ([['JORFTEXT000038261631', '1'], ['32016R0679', '17'], ['LEGITEXT000006070987', null], ['LEGIARTI000033219357', '2'], ['http://data.europa.eu/eli/reg/2016/679/oj', null]] as [$id, $number]) {
            try {
                $this->source()->article($id, $number);
                self::fail();
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    /** "Étape 2 : Récupérer le contenu de la loi avec la méthode POST /consult/legiPart". */
    public function testAConsolidatedTextAsInForceAtADate(): void
    {
        $text = $this->source()->text('LEGITEXT000038359719', new \DateTimeImmutable('2019-06-22'));

        self::assertSame(['consult/legiPart', ['textId' => 'LEGITEXT000038359719', 'date' => '2019-06-22']], [$this->calls[1]['path'], $this->calls[1]['body']]);
        self::assertSame('LEGITEXT000038359719', $text->id, 'what reads it again');
        self::assertSame('LOI n° 2019-290 (intitulé - exemple)', $text->title);
        self::assertSame('LOI', $text->type);
        self::assertSame(['legitext:LEGITEXT000038359719', 'jorftext:JORFTEXT000038358582', 'nor:JUSX1904815L', 'eli:https://www.legifrance.gouv.fr/eli/loi/2019/4/10/JUSX1904815L/jo/texte'], $text->identifiers->keys());
        self::assertSame(['2019-04-12', null, Status::IN_FORCE, 'LEGITEXT000038359719_22-06-2019'], [$text->version->from->format('Y-m-d'), $text->version->to, $text->version->status, $text->version->id]);
        self::assertTrue($text->isInForce());
        self::assertSame('2019-04-11', $text->publishedOn->format('Y-m-d'));

        self::assertSame(['1', '2', '3 bis'], array_map(static fn ($a) => $a->number, $text->articles), 'the articles of every section, in order');
        self::assertSame("(premier alinéa de l'article 1 - exemple)\n(second alinéa - exemple)", $text->articles[0]->content);
        self::assertSame('Chapitre Ier : (titre - exemple)', $text->articles[0]->path);
        self::assertSame('Chapitre II : (titre - exemple) > Section 1 : (titre - exemple)', $text->articles[2]->path);
        self::assertSame(Status::REPEALED, $text->articles[1]->version->status);
        self::assertSame('LEGIARTI000038360751', $text->article('3 BIS')?->id);
        self::assertStringStartsWith("Chapitre Ier : (titre - exemple)\n\nArticle 1\n(premier alinéa", $text->content);
        self::assertSame([Relation::AMENDS, 'LEGIARTI000006419320', Kind::ARTICLE], [$text->citations[0]->relation, $text->citations[0]->target->id, $text->citations[0]->target->kind]);
        self::assertSame('https://www.legifrance.gouv.fr/loda/id/LEGITEXT000038359719/2019-06-22', $text->url);
        self::assertArrayNotHasKey('sections', $text->raw);
    }

    public function testATextOfTheJournalOfficielByIdentifierNorOrEli(): void
    {
        $source = $this->source();
        $published = $source->text('JORFTEXT000038358582');
        self::assertSame(['consult/jorf', ['textCid' => 'JORFTEXT000038358582']], [end($this->calls)['path'], end($this->calls)['body']]);
        self::assertSame('JORFTEXT000038358582', $published->id);
        self::assertSame('https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000038358582', $published->url);
        $source->text('nor:JUSX1904815L');
        self::assertSame(['consult/getJoWithNor', ['nor' => 'JUSX1904815L']], [end($this->calls)['path'], end($this->calls)['body']]);
        $source->text('https://www.legifrance.gouv.fr/eli/loi/2019/4/10/JUSX1904815L/jo/texte');
        self::assertSame(['consult/eliAndAliasRedirectionTexte', ['idEliOrAlias' => '/eli/loi/2019/4/10/JUSX1904815L/jo/texte']], [end($this->calls)['path'], end($this->calls)['body']]);

        foreach (['http://data.europa.eu/eli/reg/2016/679/oj', 'LEGIARTI000033219357', 'Code civil'] as $id) {
            try {
                $source->text($id);
                self::fail();
            } catch (NotSupportedException) {
            }
        }
    }

    public function testADecisionOfTheJudicialCourts(): void
    {
        $decision = $this->source()->decision('https://www.legifrance.gouv.fr/juri/id/JURITEXT000037999394');

        self::assertSame(['consult/juri', ['textId' => 'JURITEXT000037999394']], [$this->calls[1]['path'], $this->calls[1]['body']]);
        self::assertSame('JURITEXT000037999394', $decision->id);
        self::assertSame('Cour d\'appel de Nancy, 7 janvier 2019, 17/030701', $decision->title);
        self::assertSame(['Cour d\'appel de Nancy', 'JX', 'NANCY'], [$decision->court->name, $decision->court->formation, $decision->court->location]);
        self::assertSame('2019-01-07', $decision->date->format('Y-m-d'));
        self::assertSame(['17/030701', '17/030702'], $decision->numbers);
        self::assertSame(['juritext:JURITEXT000037999394'], $decision->identifiers->keys(), 'a court of appeal\'s case number is no pourvoi; no ECLI given');
        self::assertSame('ARRET', $decision->type);
        self::assertSame('(résumé principal - exemple)', $decision->summary);
        self::assertSame(['(abstrats - exemple)'], $decision->subjects);
        self::assertSame('(texte intégral de la décision - exemple)', $decision->content);
        self::assertNull($decision->pseudonymised, 'the API does not say');
        self::assertSame([Relation::CITES, 'LEGIARTI000006419320'], [$decision->citations[0]->relation, $decision->citations[0]->target->id]);

        self::assertNull($this->source()->decision('JURITEXT000000000000'));
        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('it reads the judicial courts\' JURITEXT identifiers');
        $this->source()->decision('CETATEXT000042065744');
    }

    public function testTheLinksOfAnArticleBothWays(): void
    {
        $citations = $this->source()->citations('LEGIARTI000032207188');

        self::assertSame(['consult/relatedLinksArticle', ['articleId' => 'LEGIARTI000032207188']], [$this->calls[1]['path'], $this->calls[1]['body']]);
        self::assertSame([Relation::CITES, Relation::CITED_BY, Relation::CITED_BY], array_map(static fn (Citation $c) => $c->relation, $citations));
        self::assertSame(['LEGIARTI000033012294', 'Code du travail - art. L5143-1 (V)', Kind::ARTICLE, 'CODE', '2016-08-10'], [$citations[0]->target->id, $citations[0]->target->title, $citations[0]->target->kind, $citations[0]->target->type, $citations[0]->target->date->format('Y-m-d')]);
        self::assertSame(Kind::TEXT, $citations[1]->target->kind);
        self::assertSame('https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000030978561', $citations[1]->target->url);
        self::assertCount(2, $this->source()->citations('LEGIARTI000032207188', 1), 'one each way');

        self::assertCount(1, $this->source()->citations('JURITEXT000037999394'), 'a decision\'s links are those it was read with');
        $this->expectException(NotSupportedException::class);
        $this->source()->citations('LEGITEXT000006070721');
    }

    public function testTheTextsPublishedSinceADate(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $results = $this->source()->recent(new \DateTimeImmutable('2016-10-01'), new Query(types: ['ordonnance', 'decret'], limit: 10));

        self::assertSame(['list/loda', ['pageNumber' => 1, 'pageSize' => 10, 'publicationDate' => ['start' => '2016-10-01', 'end' => $today], 'natures' => ['ORDONNANCE', 'DECRET'], 'sort' => 'PUBLICATION_DATE_DESC']], [$this->calls[1]['path'], $this->calls[1]['body']]);
        self::assertSame(12, $results->total);
        self::assertSame('2', $results->next);
        self::assertSame(['LEGITEXT000033280430', Kind::TEXT, Status::IN_FORCE], [$results->items[0]->id, $results->items[0]->kind, $results->items[0]->status]);
        self::assertSame(['legitext:LEGITEXT000033280430', 'jorftext:JORFTEXT000033279563'], $results->items[0]->identifiers->keys());
        self::assertSame(Status::FUTURE, $results->items[1]->status);

        // The decisions since a date: a search by date, newest first.
        $this->source()->recent(new \DateTimeImmutable('2025-01-01'), new Query(kind: Kind::DECISION));
        $search = end($this->calls)['body'];
        self::assertSame(['JURI', 'DATE_DESC'], [$search['fond'], $search['recherche']['sort']]);
        self::assertSame(['start' => '2025-01-01', 'end' => $today], $search['recherche']['filtres'][0]['dates']);

        foreach ([new Query(kind: Kind::ARTICLE), new Query(text: 'bail'), new Query(subjects: ['Code civil'])] as $query) {
            try {
                $this->source()->recent(new \DateTimeImmutable('2025-01-01'), $query);
                self::fail();
            } catch (NotSupportedException) {
            }
        }
    }

    public function testAnyOtherMethodOfTheApi(): void
    {
        $source = $this->source(['sandbox' => true]);
        self::assertNull($source->request('consult/kaliText', ['id' => 'KALITEXT000005677408']));

        self::assertSame(Piste::SANDBOX_TOKEN_URI, $this->calls[0]['path']);
        self::assertSame(['consult/kaliText', ['id' => 'KALITEXT000005677408'], 'sandbox-api.piste.gouv.fr'], [$this->calls[1]['path'], $this->calls[1]['body'], $this->calls[1]['headers']['host']]);
    }

    public function testCredentialsAreNeededAndARefusalIsNotAnAnswer(): void
    {
        try {
            (new LegifranceSourceFactory(new MockHttpClient()))->create(['client_id' => 'only']);
            self::fail();
        } catch (InvalidConfigException $e) {
            self::assertSame('The "legifrance" source needs: client_secret.', $e->getMessage());
        }

        // What PISTE answered on 2026-10-04 to an unknown client, then to a call without a valid token.
        $refused = (new LegifranceSourceFactory(new MockHttpClient(new MockResponse('{"error":"invalid_client","error_description":"Client authentication failed (e.g. unknown client, no client authentication included, or unsupported authentication method)."}', ['http_code' => 401]))))->create(['client_id' => 'x', 'client_secret' => 'y']);
        try {
            $refused->article('LEGIARTI000033219357');
            self::fail('Refused credentials were read as "no such article".');
        } catch (AuthenticationException $e) {
            self::assertStringStartsWith('[legifrance] the client id or secret is refused', $e->getMessage());
        }

        $down = (new LegifranceSourceFactory(new MockHttpClient([new MockResponse('{"access_token":"t","expires_in":3600}'), new MockResponse('', ['http_code' => 503])])))->create(['client_id' => 'x', 'client_secret' => 'y', 'throttle' => 0]);
        $this->expectException(UnavailableException::class);
        $down->text('LEGITEXT000038359719');
    }
}
