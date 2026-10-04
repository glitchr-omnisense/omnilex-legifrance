<?php

namespace Omnilex\Legifrance;

use Omnilex\Auth\TokenProviderInterface;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Model\Article;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Citation;
use Omnilex\Model\Court;
use Omnilex\Model\Decision;
use Omnilex\Model\Identifier;
use Omnilex\Model\Identifiers;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Reference;
use Omnilex\Model\Relation;
use Omnilex\Model\Results;
use Omnilex\Model\Scheme;
use Omnilex\Model\Status;
use Omnilex\Model\Text;
use Omnilex\Model\Version;
use Omnilex\Source\ArticleReaderInterface;
use Omnilex\Source\CitationsInterface;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\HttpSource;
use Omnilex\Source\RecentInterface;
use Omnilex\Source\SearchInterface;
use Omnilex\Source\TextReaderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Légifrance, through PISTE (the DILA's API, as its Swagger and its
 * "Exemples d'utilisation de l'API" describe it):
 *
 *   POST /search                              codes, consolidated texts, case law
 *   POST /consult/legiPart                    a consolidated text as in force at a date
 *   POST /consult/jorf, /consult/getJoWithNor, /consult/eliAndAliasRedirectionTexte
 *                                             a text as published in the Journal officiel
 *   POST /consult/getArticle, /consult/getArticleWithIdAndNum, /consult/getArticleWithIdEliOrAlias
 *                                             an article, and the list of its versions
 *   POST /consult/juri                        a decision of the judicial courts
 *   POST /consult/relatedLinksArticle         what an article cites, what cites it
 *   POST /list/loda                           the laws, ordinances, decrees and orders published since a date
 *
 * Every other documented method is one request() away.
 */
final class LegifranceSource extends HttpSource implements SearchInterface, TextReaderInterface, ArticleReaderInterface, DecisionReaderInterface, CitationsInterface, RecentInterface
{
    public const BASE_URI = 'https://api.piste.gouv.fr/dila/legifrance/lf-engine-app';
    public const SANDBOX_URI = 'https://sandbox-api.piste.gouv.fr/dila/legifrance/lf-engine-app';

    /** The most results /search and /list give per page. */
    public const MAX = 100;

    /** Query::$jurisdictions, for decisions: the order of courts, each a fonds of its own. */
    private const ORDERS = [
        'judiciaire' => 'JURI', 'juri' => 'JURI',
        'administratif' => 'CETAT', 'cetat' => 'CETAT',
        'constitutionnel' => 'CONSTIT', 'constit' => 'CONSTIT',
    ];

    private const STATUS = [
        'VIGUEUR' => Status::IN_FORCE, 'VIGUEUR_ETEN' => Status::IN_FORCE, 'VIGUEUR_NON_ETEN' => Status::IN_FORCE,
        // In force today, with an end already set.
        'ABROGE_DIFF' => Status::IN_FORCE,
        'VIGUEUR_DIFF' => Status::FUTURE,
        'ABROGE' => Status::REPEALED,
        'ANNULE' => Status::ANNULLED,
        'MODIFIE' => Status::MODIFIED, 'MODIFIE_MORT_NE' => Status::MODIFIED,
        'PERIME' => Status::EXPIRED, 'TRANSFERE' => Status::EXPIRED, 'DISJOINT' => Status::EXPIRED,
        'SUBSTITUE' => Status::EXPIRED, 'REMPLACE' => Status::EXPIRED, 'DENONCE' => Status::EXPIRED,
    ];

    /** @param array<string, string> $headers */
    public function __construct(
        HttpClientInterface $http,
        TokenProviderInterface $auth,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.5,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle, $auth);
    }

    public function getName(): string
    {
        return 'legifrance';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            kinds: [Kind::TEXT, Kind::ARTICLE, Kind::DECISION],
            identifiers: [Scheme::LEGITEXT, Scheme::LEGIARTI, Scheme::JORFTEXT, Scheme::NOR, Scheme::ELI, Scheme::JURITEXT],
            criteria: Query::CRITERIA,
            jurisdictions: ['FR'],
            versions: true,
            pageSize: self::MAX,
        );
    }

    /**
     * Any method of the API, as its Swagger describes it:
     * $legifrance->request('consult/kaliText', ['id' => 'KALITEXT000005677408']).
     * Null when the API answers 404.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null
     */
    public function request(string $path, array $body = []): ?array
    {
        return $this->postJson($path, $body);
    }

    public function search(Query $query): Results
    {
        $kind = $query->kind ?? Kind::TEXT;
        $refuse = fn (string $criterion, string $why) => throw NotSupportedException::criterion($this->getName(), $criterion, $why);
        $filters = [];
        $number = 'NUM';
        $sorts = [Query::RELEVANCE => 'PERTINENCE', Query::NEWEST => 'PERTINENCE', Query::OLDEST => 'PERTINENCE'];

        if (Kind::DECISION === $kind) {
            if (\count($query->jurisdictions) > 1) {
                $refuse('jurisdictions', 'one order of courts at a time: judiciaire, administratif or constitutionnel');
            }
            $order = strtolower(trim($query->jurisdictions[0] ?? 'judiciaire'));
            $fond = self::ORDERS[$order] ?? $refuse('jurisdictions', \sprintf('"%s" is not judiciaire, administratif nor constitutionnel', $order));
            $number = 'JURI' === $fond ? 'NUM_AFFAIRE' : 'NUM_DEC';
            if ($query->types && 'JURI' !== $fond) {
                $refuse('types', 'only the judicial case law is filtered by nature (ARRET, ORDONNANCE...)');
            }
            if ($query->types) {
                $filters[] = ['facette' => 'CASSATION_NATURE_DECISION', 'valeurs' => array_map('strtoupper', array_values($query->types))];
            }
            if ($query->subjects) {
                $refuse('subjects', 'the case law is not filtered by subject');
            }
            if (null !== $query->at) {
                $refuse('at', 'a decision has no version');
            }
            if (null !== $query->from || null !== $query->to) {
                $filters[] = ['facette' => 'DATE_DECISION', 'dates' => self::period($query->from, $query->to)];
            }
            $sorts = [Query::RELEVANCE => 'PERTINENCE', Query::NEWEST => 'DATE_DESC', Query::OLDEST => 'DATE_ASC'];
        } else {
            if ($query->jurisdictions) {
                $refuse('jurisdictions', 'courts are for decisions');
            }
            $at = ($query->at ?? Query::day('now'))->format('Y-m-d');
            if (Kind::ARTICLE === $kind) {
                $fond = 'CODE_DATE';
                $number = 'NUM_ARTICLE';
                if ($query->subjects) {
                    $filters[] = ['facette' => 'NOM_CODE', 'valeurs' => array_values($query->subjects)];
                }
                if ($query->types) {
                    $refuse('types', 'the articles searched are those of the codes');
                }
                if (null !== $query->from || null !== $query->to) {
                    $refuse('from', 'articles are searched as in force on a day (at), not by date');
                }
            } else {
                $fond = 'LODA_DATE';
                if ($query->types) {
                    $filters[] = ['facette' => 'NATURE', 'valeurs' => array_map('strtoupper', array_values($query->types))];
                }
                if ($query->subjects) {
                    $refuse('subjects', 'the consolidated texts are not filtered by subject; a code\'s name filters its articles (kind: ARTICLE)');
                }
                if (null !== $query->from || null !== $query->to) {
                    $filters[] = ['facette' => 'DATE_SIGNATURE', 'dates' => self::period($query->from, $query->to)];
                }
                $sorts = [Query::RELEVANCE => 'PERTINENCE', Query::NEWEST => 'SIGNATURE_DATE_DESC', Query::OLDEST => 'SIGNATURE_DATE_ASC'];
            }
            $filters[] = ['facette' => 'DATE_VERSION', 'singleDate' => $at];
        }

        $fields = [];
        if (null !== $query->number && '' !== trim($query->number)) {
            $fields[] = self::field($number, trim($query->number), 'EXACTE');
        }
        if (null !== $query->title && '' !== trim($query->title)) {
            $fields[] = self::field('TITLE', ...self::words($query->title));
        }
        if (null !== $query->text && '' !== trim($query->text)) {
            $fields[] = self::field(Kind::ARTICLE === $kind ? 'ARTICLE' : 'ALL', ...self::words($query->text));
        }
        if (!$fields) {
            // The API's examples list a fonds with "*" for the judicial case law only.
            if ('JURI' !== $fond) {
                throw NotSupportedException::operation($this->getName(), 'search that fonds without a text, a title or a number');
            }
            $fields[] = self::field('ALL', '*', 'UN_DES_MOTS');
        }

        $limit = max(1, min($query->limit, self::MAX));
        $page = max(1, (int) ($query->cursor ?? 1));
        $data = $this->postJson('search', [
            'fond' => $fond,
            'recherche' => [
                'champs' => $fields,
                'filtres' => $filters,
                'pageNumber' => $page,
                'pageSize' => $limit,
                'operateur' => 'ET',
                'sort' => $sorts[$query->sort] ?? 'PERTINENCE',
                // As the API's examples do: by article when an article is asked by its number.
                'typePagination' => Kind::ARTICLE === $kind && null !== $query->number ? 'ARTICLE' : 'DEFAUT',
            ],
        ]) ?? [];

        $items = [];
        foreach ((array) ($data['results'] ?? []) as $result) {
            if (\is_array($result)) {
                $items = [...$items, ...(Kind::ARTICLE === $kind ? $this->articleReferences($result) : array_filter([$this->reference($result, $kind)]))];
            }
        }
        $total = isset($data['totalResultNumber']) ? (int) $data['totalResultNumber'] : null;

        return new Results($items, null !== $total && $page * $limit < $total ? (string) ($page + 1) : null, $total);
    }

    public function recent(\DateTimeInterface $since, ?Query $query = null): Results
    {
        $query ??= new Query();
        $since = Query::day($since);
        $today = Query::day('now');
        if (Kind::DECISION === $query->kind) {
            return $this->search($query->with(['from' => $since, 'to' => $today, 'sort' => Query::NEWEST]));
        }
        if (Kind::ARTICLE === $query->kind) {
            throw NotSupportedException::criterion($this->getName(), 'kind', 'what is new is listed by text or by decision, not by article');
        }
        foreach (array_diff($query->criteria(), ['kind', 'types', 'from', 'to']) as $criterion) {
            throw NotSupportedException::criterion($this->getName(), $criterion, 'the texts published since a date are filtered by nature only (types)');
        }

        $limit = max(1, min($query->limit, self::MAX));
        $page = max(1, (int) ($query->cursor ?? 1));
        $data = $this->postJson('list/loda', array_filter([
            'pageNumber' => $page,
            'pageSize' => $limit,
            'publicationDate' => ['start' => $since->format('Y-m-d'), 'end' => $today->format('Y-m-d')],
            'natures' => array_map('strtoupper', array_values($query->types)),
            'sort' => 'PUBLICATION_DATE_DESC',
        ])) ?? [];

        $items = [];
        foreach ((array) ($data['results'] ?? []) as $result) {
            if (\is_array($result) && isset($result['id'])) {
                $items[] = new Reference(
                    kind: Kind::TEXT,
                    id: (string) $result['id'],
                    title: (string) ($result['titre'] ?? $result['id']),
                    identifiers: self::ids($result['id'], $result['cid'] ?? null),
                    date: self::day($result['dateDebut'] ?? null),
                    status: self::status($result['etat'] ?? null),
                    url: 'https://www.legifrance.gouv.fr/loda/id/'.$result['id'],
                    source: $this->getName(),
                    raw: $result,
                );
            }
        }
        $total = isset($data['totalResultNumber']) ? (int) $data['totalResultNumber'] : null;

        return new Results($items, null !== $total && $page * $limit < $total ? (string) ($page + 1) : null, $total);
    }

    public function text(Identifier|string $id, ?\DateTimeInterface $at = null): ?Text
    {
        $identifier = self::identify($id, Scheme::LEGITEXT, Scheme::JORFTEXT, Scheme::NOR, Scheme::ELI) ?? throw NotSupportedException::identifier($this->getName(), (string) $id, 'read a text by');
        $date = Query::day($at ?? 'now');
        $data = match ($identifier->scheme) {
            Scheme::LEGITEXT => $this->postJson('consult/legiPart', ['textId' => $identifier->value, 'date' => $date->format('Y-m-d')]),
            // The Journal officiel publishes one state of a text: the date is not read.
            Scheme::JORFTEXT => $this->postJson('consult/jorf', ['textCid' => $identifier->value]),
            Scheme::NOR => $this->postJson('consult/getJoWithNor', ['nor' => $identifier->value]),
            default => 'FR' === $identifier->country()
                ? $this->postJson('consult/eliAndAliasRedirectionTexte', ['idEliOrAlias' => (string) parse_url($identifier->value, \PHP_URL_PATH)])
                : throw NotSupportedException::identifier($this->getName(), $identifier->value, 'read a text by (not an ELI of French law)'),
        };
        if (null === $data || (!isset($data['title']) && !isset($data['cid']) && empty($data['sections']) && empty($data['articles']))) {
            return null;
        }

        $consolidated = Scheme::LEGITEXT === $identifier->scheme;
        // What reads the same text again: the consolidated text asked for, or the text of the Journal officiel.
        $textId = $consolidated ? $identifier->value : self::bare($data['cid'] ?? $data['id'] ?? $identifier->value);
        $title = (string) ($data['title'] ?? $data['jorfText'] ?? $textId);
        $articles = $lines = [];
        $this->collect($data, [], $textId, $title, $articles, $lines);
        $version = new Version(
            self::day($data['dateDebutVersion'] ?? null) ?? ($consolidated ? null : self::day($data['dateParution'] ?? null)),
            self::day($data['dateFinVersion'] ?? null),
            self::status($data['etat'] ?? $data['jurisState'] ?? null) ?? Status::UNKNOWN,
            isset($data['id']) ? (string) $data['id'] : null,
        );

        return new Text(
            id: $textId,
            title: $title,
            identifiers: new Identifiers([
                Identifier::tryOf(Scheme::LEGITEXT, $textId) ?? Identifier::tryOf(Scheme::JORFTEXT, $textId),
                Identifier::tryOf(Scheme::JORFTEXT, self::bare($data['cid'] ?? null)),
                Identifier::tryOf(Scheme::NOR, $data['nor'] ?? null),
                isset($data['eli']) && '' !== $data['eli'] ? Identifier::tryOf(Scheme::ELI, 'https://www.legifrance.gouv.fr'.$data['eli']) : null,
                $identifier,
            ]),
            type: isset($data['nature']) && '' !== $data['nature'] ? (string) $data['nature'] : null,
            date: null,
            publishedOn: self::day($data['dateParution'] ?? null),
            version: $version,
            versions: [$version],
            content: $lines ? implode("\n\n", $lines) : null,
            articles: $articles,
            subjects: array_values(array_filter((array) ($data['motsCles'] ?? []), 'is_string')),
            citations: $this->textLinks((array) ($data['liens'] ?? [])),
            language: 'fr',
            url: $consolidated
                ? 'https://www.legifrance.gouv.fr/'.('CODE' === ($data['nature'] ?? null) ? 'codes/texte_lc' : 'loda/id').'/'.$textId.'/'.$date->format('Y-m-d')
                : Identifier::tryOf(Scheme::JORFTEXT, $textId)?->url(),
            source: $this->getName(),
            raw: array_diff_key($data, ['sections' => 1, 'articles' => 1]),
        );
    }

    public function article(Identifier|string $id, ?string $number = null, ?\DateTimeInterface $at = null): ?Article
    {
        $identifier = self::identify($id, Scheme::LEGIARTI, Scheme::LEGITEXT, Scheme::ELI) ?? throw NotSupportedException::identifier($this->getName(), (string) $id, 'read an article by');
        $number = null !== $number && '' !== trim($number) ? trim($number) : null;
        if (Scheme::LEGITEXT !== $identifier->scheme) {
            if (null !== $number) {
                throw NotSupportedException::operation($this->getName(), 'take a number along with an article\'s own identifier');
            }
            if (Scheme::ELI === $identifier->scheme && 'FR' !== $identifier->country()) {
                throw NotSupportedException::identifier($this->getName(), $identifier->value, 'read an article by (not an ELI of French law)');
            }
            $data = Scheme::LEGIARTI === $identifier->scheme
                ? $this->postJson('consult/getArticle', ['id' => $identifier->value])
                // An ELI names an article as published in the Journal officiel: /eli/decret/2021/7/13/PRMD2117108D/jo/article_1
                : $this->postJson('consult/getArticleWithIdEliOrAlias', ['idEliOrAlias' => (string) parse_url($identifier->value, \PHP_URL_PATH)]);
        } else {
            if (null === $number) {
                throw NotSupportedException::operation($this->getName(), 'read an article of a text without its number');
            }
            $data = $this->postJson('consult/getArticleWithIdAndNum', ['id' => $identifier->value, 'num' => $number]);
            // Named by its number, an article is the one in force: today, unless a day is given.
            $at ??= Query::day('now');
        }
        $article = $data['article'] ?? null;
        if (!\is_array($article) || !isset($article['id'])) {
            return null;
        }

        $versions = [];
        foreach ((array) ($article['articleVersions'] ?? []) as $v) {
            if (\is_array($v) && isset($v['id'])) {
                $versions[] = new Version(self::day($v['dateDebut'] ?? null), self::day($v['dateFin'] ?? null), self::status($v['etat'] ?? null) ?? Status::UNKNOWN, (string) $v['id'], isset($v['version']) ? (string) $v['version'] : null);
            }
        }
        usort($versions, static fn (Version $a, Version $b) => $a->from <=> $b->from);
        $own = new Version(self::day($article['dateDebut'] ?? null), self::day($article['dateFin'] ?? null), self::status($article['etat'] ?? null) ?? Status::UNKNOWN, (string) $article['id'], isset($article['versionArticle']) ? (string) $article['versionArticle'] : null);

        if (null !== $at) {
            $wanted = Version::at($versions ?: [$own], $at);
            if (null === $wanted) {
                return null;
            }
            if (null !== $wanted->id && $wanted->id !== $article['id']) {
                $article = $this->postJson('consult/getArticle', ['id' => $wanted->id])['article'] ?? null;
                if (!\is_array($article) || !isset($article['id'])) {
                    return null;
                }
                $own = new Version(self::day($article['dateDebut'] ?? null) ?? $wanted->from, self::day($article['dateFin'] ?? null) ?? $wanted->to, self::status($article['etat'] ?? null) ?? $wanted->status, (string) $article['id'], $wanted->label);
            }
        }

        $text = \is_array($article['textTitles'][0] ?? null) ? $article['textTitles'][0] : [];
        $num = isset($article['num']) && '' !== $article['num'] ? (string) $article['num'] : $number;
        $citations = [];
        foreach ([...(array) ($article['lienModifications'] ?? []), ...(array) ($article['lienCitations'] ?? [])] as $link) {
            if (\is_array($link) && (isset($link['textCid']) || isset($link['articleId']))) {
                $citations[] = $this->articleLink($link);
            }
        }
        $html = isset($article['texteHtml']) && '' !== $article['texteHtml'] ? (string) $article['texteHtml'] : null;

        return new Article(
            id: (string) $article['id'],
            number: $num,
            content: (string) (isset($article['texte']) && '' !== trim((string) $article['texte']) ? trim((string) $article['texte']) : self::plain($html)),
            html: $html,
            title: null,
            identifiers: self::ids($article['id'], $article['cid'] ?? null),
            version: $own,
            versions: $versions,
            textId: self::bare($article['cidTexte'] ?? $text['cid'] ?? $article['idTexte'] ?? null),
            textTitle: isset($text['titre']) ? (string) $text['titre'] : (isset($text['titreLong']) ? (string) $text['titreLong'] : null),
            path: isset($article['fullSectionsTitre']) && '' !== $article['fullSectionsTitre'] ? (string) $article['fullSectionsTitre'] : ($article['sectionParentTitre'] ?? null),
            note: isset($article['nota']) && '' !== trim((string) $article['nota']) ? trim((string) $article['nota']) : self::plain($article['notaHtml'] ?? null),
            citations: $citations,
            url: 'https://www.legifrance.gouv.fr/'.('CODE' === ($text['nature'] ?? null) ? 'codes' : 'loda').'/article_lc/'.$article['id'],
            source: $this->getName(),
            raw: array_diff_key($article, ['texte' => 1, 'texteHtml' => 1]),
        );
    }

    public function decision(Identifier|string $id): ?Decision
    {
        // The Swagger documents /consult/juri for the JURITEXT identifiers of the judicial courts.
        $identifier = self::identify($id, Scheme::JURITEXT) ?? throw NotSupportedException::identifier($this->getName(), (string) $id, 'read a decision by (it reads the judicial courts\' JURITEXT identifiers)');
        $text = $this->postJson('consult/juri', ['textId' => $identifier->value])['text'] ?? null;
        if (!\is_array($text) || (!isset($text['id']) && !isset($text['titre']))) {
            return null;
        }
        $numbers = array_values(array_filter(array_map('strval', (array) ($text['numeroAffaire'] ?? []))));
        $summaries = $headings = [];
        foreach ((array) ($text['sommaire'] ?? []) as $summary) {
            if (\is_array($summary)) {
                $summaries[] = self::plain($summary['resumePrincipal'] ?? null);
                $headings[] = self::plain($summary['abstrats'] ?? null);
            }
        }
        $html = isset($text['texteHtml']) && '' !== $text['texteHtml'] ? (string) $text['texteHtml'] : null;
        $court = $text['juridiction'] ?? $text['natureJuridiction'] ?? null;

        return new Decision(
            id: (string) ($text['id'] ?? $identifier->value),
            title: isset($text['titre']) ? (string) $text['titre'] : null,
            identifiers: new Identifiers([
                $identifier,
                Identifier::tryOf(Scheme::ECLI, $text['ecli'] ?? null),
                ...array_map(static fn (string $n) => Identifier::tryOf(Scheme::POURVOI, $n), $numbers),
            ]),
            court: \is_string($court) && '' !== $court ? new Court($court, null, 'FR', null, isset($text['formation']) && '' !== $text['formation'] ? (string) $text['formation'] : null, $text['siegeAppel'] ?? null) : null,
            date: self::day($text['dateTexte'] ?? null),
            number: $numbers[0] ?? null,
            numbers: $numbers,
            type: isset($text['nature']) && '' !== $text['nature'] ? (string) $text['nature'] : null,
            solution: isset($text['solution']) && '' !== $text['solution'] ? (string) $text['solution'] : null,
            publication: $text['typePublicationBulletin'] ?? $text['publicationRecueil'] ?? null,
            summary: implode("\n\n", array_filter($summaries)) ?: null,
            subjects: array_values(array_filter($headings)),
            content: isset($text['texte']) && '' !== trim((string) $text['texte']) ? trim((string) $text['texte']) : self::plain($html),
            html: $html,
            citations: $this->textLinks((array) ($text['liens'] ?? [])),
            pseudonymised: null,
            updatedOn: self::day($text['dateDerniereModif'] ?? null),
            language: 'fr',
            url: $identifier->url(),
            source: $this->getName(),
            raw: array_diff_key($text, ['texte' => 1, 'texteHtml' => 1]),
        );
    }

    public function citations(Identifier|string $id, int $limit = 100): array
    {
        $identifier = self::identify($id, Scheme::LEGIARTI, Scheme::JURITEXT) ?? throw NotSupportedException::identifier($this->getName(), (string) $id, 'follow the links of (it follows those of an article, LEGIARTI, and of a judicial decision, JURITEXT)');
        $limit = max(1, $limit);
        if (Scheme::JURITEXT === $identifier->scheme) {
            return \array_slice($this->decision($identifier)?->citations ?? [], 0, $limit);
        }
        $data = $this->postJson('consult/relatedLinksArticle', ['articleId' => $identifier->value]) ?? [];
        $citations = [];
        foreach (['liensCite' => Relation::CITES, 'liensCitePar' => Relation::CITED_BY] as $key => $relation) {
            foreach (\array_slice((array) ($data[$key] ?? []), 0, $limit) as $link) {
                if (\is_array($link) && isset($link['id'])) {
                    $target = Identifier::parse((string) $link['id']);
                    $citations[] = new Citation($relation, new Reference(
                        kind: $target?->scheme->kind() ?? Kind::TEXT,
                        id: (string) $link['id'],
                        title: (string) ($link['name'] ?? $link['id']),
                        identifiers: self::ids($link['id'], $link['cidText'] ?? null),
                        date: self::day($link['dateVigeur'] ?? null),
                        type: $link['nature'] ?? null,
                        url: $target?->url(),
                        source: $this->getName(),
                        raw: $link,
                    ));
                }
            }
        }

        return $citations;
    }

    /**
     * Walks a text's sections: the articles in order, and the text as lines.
     *
     * @param array<string, mixed> $node    a text or a section: "sections", "articles"
     * @param list<string>         $path    the headings above
     * @param list<Article>        $articles
     * @param list<string>         $lines
     */
    private function collect(array $node, array $path, string $textId, string $textTitle, array &$articles, array &$lines): void
    {
        foreach ((array) ($node['articles'] ?? []) as $article) {
            if (!\is_array($article) || !isset($article['id'])) {
                continue;
            }
            $html = isset($article['content']) && '' !== $article['content'] ? (string) $article['content'] : null;
            $content = (string) self::plain($html);
            $number = isset($article['num']) && '' !== $article['num'] ? (string) $article['num'] : null;
            $headings = array_values(array_filter((array) ($article['pathTitle'] ?? []), 'is_string')) ?: $path;
            $articles[] = new Article(
                id: (string) $article['id'],
                number: $number,
                content: $content,
                html: $html,
                identifiers: self::ids($article['id'], $article['cid'] ?? null),
                version: new Version(null, null, self::status($article['etat'] ?? null) ?? Status::UNKNOWN, (string) $article['id'], isset($article['articleVersion']) ? (string) $article['articleVersion'] : null),
                textId: $textId,
                textTitle: $textTitle,
                path: $headings ? implode(' > ', $headings) : null,
                note: self::plain($article['nota'] ?? null),
                source: $this->getName(),
            );
            $lines[] = trim((null !== $number ? 'Article '.$number."\n" : '').$content);
        }
        foreach ((array) ($node['sections'] ?? []) as $section) {
            if (\is_array($section)) {
                $title = isset($section['title']) && '' !== trim((string) $section['title']) ? trim((string) $section['title']) : null;
                if (null !== $title) {
                    $lines[] = $title;
                }
                $this->collect($section, null !== $title ? [...$path, $title] : $path, $textId, $textTitle, $articles, $lines);
            }
        }
    }

    /**
     * One result of /search as a text or a decision.
     *
     * @param array<string, mixed> $result
     */
    private function reference(array $result, Kind $kind): ?Reference
    {
        $title = \is_array($result['titles'][0] ?? null) ? $result['titles'][0] : [];
        $id = $title['id'] ?? $title['cid'] ?? null;
        if (!\is_string($id) || '' === $id) {
            return null;
        }
        $excerpt = $result['text'] ?? null;
        foreach ((array) ($result['sections'] ?? []) as $section) {
            foreach ((array) ($section['extracts'] ?? []) as $extract) {
                $excerpt ??= $extract['values'][0] ?? null;
            }
        }
        $identifier = Identifier::parse(self::bare($id));

        return new Reference(
            kind: $kind,
            id: self::bare($id),
            title: (string) (self::plain($title['title'] ?? null) ?? $id),
            identifiers: new Identifiers([$identifier, Identifier::parse((string) ($title['cid'] ?? '')), Identifier::tryOf(Scheme::NOR, $result['nor'] ?? null)]),
            date: self::day($result['date'] ?? $result['dateSignature'] ?? $result['datePublication'] ?? null),
            type: $result['nature'] ?? $title['nature'] ?? null,
            number: isset($result['num']) && '' !== $result['num'] ? (string) $result['num'] : null,
            status: self::status($result['etat'] ?? $title['legalStatus'] ?? null),
            summary: self::plain(\is_string($excerpt) ? $excerpt : null),
            url: Kind::DECISION === $kind ? $identifier?->url() : (Scheme::LEGITEXT === $identifier?->scheme ? 'https://www.legifrance.gouv.fr/loda/id/'.$identifier->value : $identifier?->url()),
            source: $this->getName(),
            raw: $result,
        );
    }

    /**
     * One result of a search in the articles of the codes: each article found.
     *
     * @param array<string, mixed> $result
     *
     * @return list<Reference>
     */
    private function articleReferences(array $result): array
    {
        $text = \is_array($result['titles'][0] ?? null) ? $result['titles'][0] : [];
        $code = self::plain($text['title'] ?? null);
        $references = [];
        foreach ((array) ($result['sections'] ?? []) as $section) {
            foreach ((array) ($section['extracts'] ?? []) as $extract) {
                if (!\is_array($extract) || !isset($extract['id'])) {
                    continue;
                }
                $number = isset($extract['num']) && '' !== $extract['num'] ? (string) $extract['num'] : null;
                $references[] = new Reference(
                    kind: Kind::ARTICLE,
                    id: (string) $extract['id'],
                    title: trim(($code ?? '').(null !== $number ? ' - art. '.$number : ''), ' -') ?: (string) $extract['id'],
                    identifiers: self::ids($extract['id'], $text['cid'] ?? $text['id'] ?? null),
                    date: self::day($extract['dateDebut'] ?? $extract['dateVersion'] ?? null),
                    type: $extract['type'] ?? null,
                    number: $number,
                    status: self::status($extract['legalStatus'] ?? null),
                    summary: self::plain(implode(' … ', array_filter((array) ($extract['values'] ?? []), 'is_string'))),
                    url: 'https://www.legifrance.gouv.fr/codes/article_lc/'.$extract['id'],
                    source: $this->getName(),
                    raw: $extract + ['text' => $text],
                );
            }
        }

        return $references;
    }

    /**
     * The links of a text or of a decision to other texts.
     *
     * @param list<mixed> $links TexteLien: title, cidTexte, id, typeLien, sens, natureTexte, norTexte
     *
     * @return list<Citation>
     */
    private function textLinks(array $links): array
    {
        $citations = [];
        foreach ($links as $link) {
            if (!\is_array($link) || (!isset($link['cidTexte']) && !isset($link['id']) && !isset($link['title']))) {
                continue;
            }
            $id = (string) ($link['id'] ?? $link['cidTexte'] ?? '');
            $target = Identifier::parse($id);
            $citations[] = new Citation(
                self::relation($link['typeLien'] ?? null, $link['sens'] ?? null),
                new Reference(
                    kind: $target?->scheme->kind() ?? Kind::TEXT,
                    id: $id,
                    title: (string) (self::plain($link['title'] ?? null) ?? $id),
                    identifiers: new Identifiers([$target, Identifier::parse((string) ($link['cidTexte'] ?? '')), Identifier::tryOf(Scheme::NOR, $link['norTexte'] ?? null)]),
                    date: self::day($link['dateSignaTexte'] ?? $link['datePubliTexte'] ?? null),
                    type: $link['natureTexte'] ?? null,
                    number: $link['numTexte'] ?? $link['num'] ?? null,
                    url: $target?->url(),
                    source: $this->getName(),
                    raw: $link,
                ),
                isset($link['typeLien']) ? (string) $link['typeLien'] : null,
            );
        }

        return $citations;
    }

    /** @param array<string, mixed> $link LienModification or LienCitation */
    private function articleLink(array $link): Citation
    {
        $id = (string) ($link['articleId'] ?? $link['textCid'] ?? '');
        $target = Identifier::parse($id);

        return new Citation(
            self::relation($link['linkType'] ?? null, $link['linkOrientation'] ?? null),
            new Reference(
                kind: $target?->scheme->kind() ?? Kind::TEXT,
                id: $id,
                title: (string) ($link['textTitle'] ?? $id),
                identifiers: new Identifiers([$target, Identifier::parse((string) ($link['textCid'] ?? ''))]),
                date: self::day($link['dateSignaTexte'] ?? $link['datePubliTexte'] ?? $link['datePubli'] ?? null),
                type: $link['natureText'] ?? null,
                number: $link['articleNum'] ?? null,
                url: $target?->url(),
                source: $this->getName(),
                raw: $link,
            ),
            isset($link['linkType']) ? (string) $link['linkType'] : null,
        );
    }

    /**
     * Légifrance types its links (CITATION, MODIFIE, ABROGE, CREE...) and
     * says which end the document is: "source" when it acts, "cible" when
     * it is acted upon.
     */
    private static function relation(mixed $type, mixed $orientation): Relation
    {
        $type = \is_string($type) ? strtoupper($type) : '';
        $target = \is_string($orientation) && 'cible' === strtolower($orientation);

        return match (true) {
            str_contains($type, 'CITATION') || '' === $type => $target ? Relation::CITED_BY : Relation::CITES,
            str_contains($type, 'ABROG') => $target ? Relation::REPEALED_BY : Relation::REPEALS,
            default => $target ? Relation::AMENDED_BY : Relation::AMENDS,
        };
    }

    private static function status(mixed $state): ?Status
    {
        return \is_string($state) && '' !== $state ? (self::STATUS[strtoupper($state)] ?? Status::UNKNOWN) : null;
    }

    /**
     * @return array{typeChamp: string, criteres: list<array{typeRecherche: string, valeur: string, operateur: string}>, operateur: string}
     */
    private static function field(string $field, string $value, string $search): array
    {
        return ['typeChamp' => $field, 'criteres' => [['typeRecherche' => $search, 'valeur' => $value, 'operateur' => 'ET']], 'operateur' => 'ET'];
    }

    /**
     * Words, and how they are searched: all of them, or as the exact expression when in double quotes.
     *
     * @return array{0: string, 1: string}
     */
    private static function words(string $text): array
    {
        $text = trim($text);

        return preg_match('~^"(.+)"$~su', $text, $m) ? [trim($m[1]), 'EXACTE'] : [$text, 'TOUS_LES_MOTS_DANS_UN_CHAMP'];
    }

    /** @return array{start?: string, end?: string} */
    private static function period(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        return array_filter(['start' => $from?->format('Y-m-d'), 'end' => $to?->format('Y-m-d')]);
    }

    /** A consulted text's id carries its date ("LEGITEXT000006070994_01-08-2016"): the identifier alone. */
    private static function bare(mixed $id): string
    {
        return \is_string($id) ? (string) preg_replace('~_\d{2}-\d{2}-\d{4}$~', '', $id) : '';
    }

    private static function ids(mixed ...$ids): Identifiers
    {
        return new Identifiers(array_map(static fn ($id) => \is_string($id) ? Identifier::parse(self::bare($id)) : null, $ids));
    }
}
