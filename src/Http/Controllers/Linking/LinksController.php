<?php

namespace Statamic\SeoPro\Http\Controllers\Linking;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\Scope;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\SeoPro\Contracts\TextProcessing\ConfigurationRepository;
use Statamic\SeoPro\Contracts\TextProcessing\Content\ContentRetriever;
use Statamic\SeoPro\Hooks\CP\EntryLinksIndexQuery;
use Statamic\SeoPro\Http\Concerns\MergesBlueprintFields;
use Statamic\SeoPro\Http\Requests\InsertLinkRequest;
use Statamic\SeoPro\Http\Requests\UpdateEntryLinkRequest;
use Statamic\SeoPro\Http\Resources\Links\EntryLinks;
use Statamic\SeoPro\Reporting\Linking\ReportBuilder;
use Statamic\SeoPro\TextProcessing\Config\CollectionConfig;
use Statamic\SeoPro\TextProcessing\Config\EntryConfigBlueprint;
use Statamic\SeoPro\TextProcessing\Content\ContentMapper;
use Statamic\SeoPro\TextProcessing\Content\LinkReplacement;
use Statamic\SeoPro\TextProcessing\Content\LinkReplacer;
use Statamic\SeoPro\TextProcessing\Models\AutomaticLink;
use Statamic\SeoPro\TextProcessing\Models\EntryLink;
use Statamic\SeoPro\TextProcessing\Models\EntryLink as EntryLinksModel;

class LinksController extends CpController
{
    use QueriesFilters, MergesBlueprintFields;

    protected array $sortFieldMappings = [
        'title' => 'cached_title',
        'slug' => 'cached_slug',
    ];

    public function __construct(
        Request $request,
        protected readonly ConfigurationRepository $configurationRepository,
        protected readonly ReportBuilder $reportBuilder,
        protected readonly ContentRetriever $contentRetriever,
        protected readonly LinkReplacer $linkReplacer,
        protected readonly ContentMapper $contentMapper,
    )
    {
        parent::__construct($request);
    }

    protected function mergeEntryConfigBlueprint(array $target = []): array
    {
        return $this->mergeBlueprintIntoContext(
            EntryConfigBlueprint::blueprint(),
            $target,
            callback: function (&$values) {
                $values['can_be_suggested'] = true;
                $values['include_in_reporting'] = true;
            }
        );
    }

    protected function makeDashboardResponse(string $entryId, string $tab, string $title)
    {
        return view('seo-pro::linking.dashboard', $this->mergeEntryConfigBlueprint([
            'report' => $this->reportBuilder->getBaseReport(Entry::findOrFail($entryId)),
            'tab' => $tab,
            'title' => $title,
        ]));
    }

    public function index(Request $request)
    {
        $site = $request->site ? Site::get($request->site) : Site::selected();

        return view('seo-pro::linking.index', $this->mergeEntryConfigBlueprint([
            'site' => $site->handle(),
            'filters' => Scope::filters('seo_pro.links', $this->makeFiltersContext()),
        ]));
    }

    public function getLink(string $link)
    {
        return EntryLink::where('entry_id', $link)->firstOrFail();
    }

    public function updateLink(UpdateEntryLinkRequest $request, string $link)
    {
        /** @var EntryLink $entryLink */
        $entryLink = EntryLink::where('entry_id', $link)->firstOrFail();

        $entryLink->can_be_suggested = $request->get('can_be_suggested');
        $entryLink->include_in_reporting = $request->get('include_in_reporting');

        $entryLink->save();
    }

    public function resetEntrySuggestions(string $link)
    {
        /** @var EntryLink $entryLink */
        $entryLink = EntryLink::where('entry_id', $link)->firstOrFail();

        $entryLink->ignored_entries = [];
        $entryLink->ignored_phrases = [];

        $entryLink->save();
    }

    public function filter(FilteredRequest $request)
    {
        $sortField = $this->getSortField();
        $sortDirection = request('order', 'asc');

        $query = $this->indexQuery();

        $activeFilterBadges = $this->queryFilters($query, $request->filters);

        if ($sortField) {
            $query->orderBy($sortField, $sortDirection);
        }

        if (request('search')) {
            $query->where(function (Builder $q) {
                $q->where('analyzed_content', 'like', '%'.request('search').'%')
                    ->orWhere('cached_title', 'like', '%'.request('search').'%')
                    ->orWhere('cached_uri', 'like', '%'.request('search').'%');
            });
        }

        $links = (new EntryLinksIndexQuery($query))->paginate(request('perPage'));

        return (new EntryLinks($links))
            ->additional(['meta' => [
                'activeFilterBadges' => $activeFilterBadges,
            ]]);
    }

    public function getOverview()
    {
        // TODO: Revisit this.
        $entriesAnalyzed = EntryLinksModel::query()->count();
        $orphanedEntries = EntryLinksModel::query()->where('inbound_internal_link_count', 0)->count();

        $entriesNeedingMoreLinks = EntryLinksModel::query()
            ->where('include_in_reporting', true)
            ->where('internal_link_count', '=', 0)->count();

        return [
            'total' => $entriesAnalyzed,
            'orphaned' => $orphanedEntries,
            'needs_links' => $entriesNeedingMoreLinks
        ];
    }

    public function getSuggestions($entryId)
    {
        if (request()->ajax()) {
            return $this->reportBuilder->getSuggestionsReport(Entry::findOrFail($entryId))->suggestions();
        }

        return $this->makeDashboardResponse($entryId, 'suggestions', 'Link Suggestions');
    }

    public function getLinkFieldDetails($entryId, $fieldPath)
    {
        $entry = Entry::findOrFail($entryId);

        return $this->contentMapper->getFieldConfigForEntry($entry, $fieldPath)?->toArray() ?? [];
    }

    public function getRelatedContent($entryId)
    {
        if (request()->ajax()) {
            return $this->reportBuilder->getRelatedContentReport(Entry::findOrFail($entryId))->getRelated();
        }

        return $this->makeDashboardResponse($entryId, 'related', 'Related Content');
    }

    public function getInternalLinks($entryId)
    {
        if (request()->ajax()) {
            return $this->reportBuilder->getInternalLinks(Entry::findOrFail($entryId))->getLinks();
        }

        return $this->makeDashboardResponse($entryId, 'internal', 'Internal Links');
    }

    public function getExternalLinks($entryId)
    {
        if (request()->ajax()) {
            return $this->reportBuilder->getExternalLinks(Entry::findOrFail($entryId))->getLinks();
        }

        return $this->makeDashboardResponse($entryId, 'external', 'External Links');
    }

    public function getInboundInternalLinks($entryId)
    {
        if (request()->ajax()) {
            return $this->reportBuilder->getInboundInternalLinks(Entry::findOrFail($entryId))->getLinks();
        }

        return $this->makeDashboardResponse($entryId, 'inbound', 'Inbound Internal Links');
    }

    public function getSections($entryId)
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            return [];
        }

        return $this->contentRetriever->getSections($entry);
    }

    protected function makeReplacementFromRequest(): LinkReplacement
    {
        return new LinkReplacement(
            request('phrase') ?? '',
            request('section') ?? '',
            request('target') ?? '',
            request('field') ?? ''
        );
    }

    public function checkLinkReplacement(InsertLinkRequest $request)
    {
        $entry = Entry::findOrFail(request('entry'));

        return [
            'can_replace' => $this->linkReplacer->canReplace(
                $entry,
                $this->makeReplacementFromRequest(),
            ),
        ];
    }

    public function insertLink(InsertLinkRequest $request)
    {
        $entry = Entry::findOrFail(request('entry'));

        if ($request->get('auto_link', false) === true && request('auto_link_entry')) {
            $site = $entry->site()?->handle() ?? $request->site ? Site::get($request->site) : Site::selected();
            $autoLinkEntry = Entry::find(request('auto_link_entry'));

            $link = new AutomaticLink();
            $link->site = $site->handle();
            $link->is_active = true;
            $link->link_text = request('phrase');
            $link->link_target = $autoLinkEntry->uri();
            $link->entry_id = request('auto_link_entry');

            $link->save();
        }

        $this->linkReplacer->replaceLink(
            $entry,
            $this->makeReplacementFromRequest(),
        );
    }

    private function getSortField(): string
    {
        $sortField = request('sort', 'title');

        if (! $sortField) {
            return $sortField;
        }

        $checkField = strtolower($sortField);

        if (array_key_exists($checkField, $this->sortFieldMappings)) {
            $sortField = $this->sortFieldMappings[$checkField];
        }

        return $sortField;
    }

    protected function indexQuery(): Builder
    {
        $disabledCollections = $this->configurationRepository->getDisabledCollections();

        return EntryLinksModel::query()->whereNotIn('collection', $disabledCollections);
    }

    protected function makeFiltersContext(): array
    {
        $collections = $this->configurationRepository
            ->getCollections()
            ->where(fn(CollectionConfig $config) => $config->linkingEnabled)
            ->map(fn(CollectionConfig $config) => $config->handle)
            ->all();

        $sites = Site::all()
            ->map(fn($site) => $site->handle())
            ->values()
            ->all();

        return [
            'collections' => $collections,
            'sites' => $sites,
        ];
    }
}