<?php

namespace MediaWiki\Rest\Handler;

use ISearchResultSet;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\Handler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use RequestContext;
use SearchEngine;
use SearchEngineConfig;
use SearchEngineFactory;
use SearchResult;
use Status;
use User;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * Handler class for Core REST API endpoint that handles basic search
 */
class SearchHandler extends Handler {

	/** @var PermissionManager */
	private $permissionManager;

	/** @var SearchEngineFactory */
	private $searchEngineFactory;

	/** @var SearchEngineConfig */
	private $searchEngineConfig;

	/** @var User */
	private $user;

	/** Limit results to 50 pages per default */
	private const LIMIT = 50;

	/** Hard limit results to 100 pages */
	private const MAX_LIMIT = 100;

	/** Default to first page */
	private const OFFSET = 0;

	/**
	 * @param PermissionManager $permissionManager
	 * @param SearchEngineFactory $searchEngineFactory
	 * @param SearchEngineConfig $searchEngineConfig
	 */
	public function __construct(
		PermissionManager $permissionManager,
		SearchEngineFactory $searchEngineFactory,
		SearchEngineConfig $searchEngineConfig
	) {
		$this->permissionManager = $permissionManager;
		$this->searchEngineFactory = $searchEngineFactory;
		$this->searchEngineConfig = $searchEngineConfig;

		// @todo Inject this, when there is a good way to do that
		$this->user = RequestContext::getMain()->getUser();
	}

	/**
	 * @return SearchEngine
	 */
	private function createSearchEngine() {
		$limit = $this->getValidatedParams()['limit'];

		$searchEngine = $this->searchEngineFactory->create();
		$searchEngine->setNamespaces( $this->searchEngineConfig->defaultNamespaces() );
		$searchEngine->setLimitOffset( $limit, self::OFFSET );
		return $searchEngine;
	}

	public function needsWriteAccess() {
		return false;
	}

	/**
	 * Get SearchResults when results are either SearchResultSet or Status objects
	 * @param ISearchResultSet|Status|null $results
	 * @return SearchResult[]
	 * @throws LocalizedHttpException
	 */
	private function getResultsOrThrow( $results ) {
		if ( $results ) {
			if ( $results instanceof Status ) {
				$status = $results;
				if ( !$status->isOK() ) {
					list( $error ) = $status->splitByErrorType();
					if ( $error->getErrors() ) { // Only throw for errors, suppress warnings (for now)
						$errorMessages = $error->getMessage();
						throw new LocalizedHttpException(
							new MessageValue( "rest-search-error", [ $errorMessages->getKey() ] )
						);
					}
				}
				$statusValue = $status->getValue();
				if ( $statusValue instanceof ISearchResultSet ) {
					return $statusValue->extractResults();
				}
			} else {
				return $results->extractResults();
			}
		}
		return [];
	}

	/**
	 * Execute search on both title and text fields
	 * @param SearchEngine $searchEngine
	 * @return SearchResult[]
	 * @throws LocalizedHttpException
	 */
	private function doSearch( $searchEngine ) {
		$query = $this->getValidatedParams()['q'];

		$titleSearch = $searchEngine->searchTitle( $query );
		$textSearch = $searchEngine->searchText( $query );

		$titleSearchResults = $this->getResultsOrThrow( $titleSearch );
		$textSearchResults = $this->getResultsOrThrow( $textSearch );

		$mergedResults = array_merge( $titleSearchResults, $textSearchResults );
		return $mergedResults;
	}

	/**
	 * Remove duplicate pages and turn results into response json objects
	 * @param SearchResult[] $textAndTitleResults
	 * @return array page objects
	 */
	private function parseResults( $textAndTitleResults ) {
		$pages = [];
		$foundPageIds = [];
		foreach ( $textAndTitleResults as $result ) {
			if ( !$result->isBrokenTitle() && !$result->isMissingRevision() ) {
				$title = $result->getTitle();
				$pageID = $title->getArticleID();
				if ( !isset( $foundPageIds[$pageID] ) &&
					$this->permissionManager->userCan( 'read', $this->user, $title )
				) {
					$page = [
						'id' => $pageID,
						'key' => $title->getPrefixedDbKey(),
						'title' => $title->getPrefixedText(),
						'excerpt' => $result->getTextSnippet() ?: null,
					];
					$pages[] = $page;
					$foundPageIds[$pageID] = true;
				}
			}
		}
		return $pages;
	}

	/**
	 * @return Response
	 * @throws LocalizedHttpException
	 */
	public function execute() {
		$searchEngine = $this->createSearchEngine();
		$results = $this->doSearch( $searchEngine );
		$pages = $this->parseResults( $results );
		return $this->getResponseFactory()->createJson( [ 'pages' => $pages ] );
	}

	public function getParamSettings() {
		return [
			'q' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'limit' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => self::LIMIT,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => self::MAX_LIMIT,
			],
		];
	}
}
