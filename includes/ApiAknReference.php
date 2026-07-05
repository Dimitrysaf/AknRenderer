<?php
/**
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AknRenderer;

use ApiBase;
use ApiMain;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Rdbms\IConnectionProvider;

class ApiAknReference extends ApiBase
{
	private IConnectionProvider $dbProvider;

	public function __construct(ApiMain $mainModule, string $moduleName, IConnectionProvider $dbProvider)
	{
		parent::__construct($mainModule, $moduleName);
		$this->dbProvider = $dbProvider;
	}

	public function execute()
	{
		$params = $this->extractRequestParams();
		if ($params['op'] === 'search') {
			$this->doSearch($params['query'] ?? '');
		} else {
			$this->doEids($params['title'] ?? null, $params['pageid'] ?? null);
		}
	}

	private function doSearch(string $query): void
	{
		$result = $this->getResult();
		$query = trim($query);
		if ($query === '') {
			$result->addValue(null, $this->getModuleName(), ['matches' => []]);
			return;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		$like = $dbr->buildLike($dbr->anyString(), $query, $dbr->anyString());
		$rows = $dbr->newSelectQueryBuilder()
			->select(['page_id', 'page_namespace', 'page_title', 'am_work_uri', 'am_alias'])
			->from('akn_meta')
			->join('page', null, 'am_page = page_id')
			->where($dbr->makeList([
				'page_title ' . $like,
				'am_work_uri ' . $like,
				'am_alias ' . $like,
			], LIST_OR))
			->orderBy('page_title')
			->limit(20)
			->caller(__METHOD__)
			->fetchResultSet();

		$matches = [];
		foreach ($rows as $row) {
			$title = Title::makeTitle((int) $row->page_namespace, $row->page_title);
			$matches[] = [
				'pageid' => (int) $row->page_id,
				'title' => $title->getPrefixedText(),
				'workUri' => $row->am_work_uri,
				'alias' => $row->am_alias,
			];
		}
		$result->addValue(null, $this->getModuleName(), ['matches' => $matches]);
	}

	private function doEids(?string $titleText, ?int $pageId): void
	{
		$result = $this->getResult();

		if ($pageId === null) {
			if ($titleText === null || $titleText === '') {
				$this->dieWithError(['apierror-missingparam', 'title|pageid']);
			}
			$title = Title::newFromText($titleText);
			if (!$title || !$title->exists()) {
				$result->addValue(null, $this->getModuleName(), ['eids' => []]);
				return;
			}
			$pageId = $title->getArticleID();
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select(['ast_eid', 'ast_num', 'ast_heading'])
			->from('akn_structure')
			->where(['ast_page' => $pageId])
			->orderBy('ast_order')
			->caller(__METHOD__)
			->fetchResultSet();

		$eids = [];
		foreach ($rows as $row) {
			$eids[] = [
				'eid' => $row->ast_eid,
				'num' => $row->ast_num,
				'heading' => $row->ast_heading,
			];
		}
		$result->addValue(null, $this->getModuleName(), ['eids' => $eids]);
	}

	public function getAllowedParams()
	{
		return [
			'op' => [
				ParamValidator::PARAM_TYPE => ['search', 'eids'],
				ParamValidator::PARAM_REQUIRED => true,
			],
			'query' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
			],
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
		];
	}

	public function isInternal()
	{
		return false;
	}

	public function isReadMode()
	{
		return true;
	}

	protected function getExamplesMessages()
	{
		return [
			'action=aknreference&op=search&query=2024' => 'apihelp-aknreference-example-search',
			'action=aknreference&op=eids&title=Law:Example' => 'apihelp-aknreference-example-eids',
		];
	}
}
