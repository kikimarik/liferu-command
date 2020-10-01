<?php
namespace console\controllers;
use yii\console\Controller;
use yii\httpclient\Client;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

/**
 * LiferuController
 */
class LiferuController extends Controller
{
	private ?Client $_client = null;
	private string $_feedUrl = '';
	private string $_postStorageUrl = '';
    private array $_feedRequestSettings = [];
	private array $_blocks = [];
	private array $_md5Columns = [];
    private string $_blocksFile = '';
    private string $_feedFile = '';

	public function init()
	{
		$this->_client = new Client();
		$this->_feedUrl = 'https://api.corr.life/public/posts/lenta';
		$this->_postStorageUrl = 'https://api.corr.life/public/posts/';
        $this->_feedRequestSettings = [
            'section' => 'novosti',
            'after' => time()*1000,
        ];
		$this->_md5Columns[] = 'content';
        $fileStorage = \Yii::getAlias('@console/storage');
        if (!file_exists($fileStorage)) {
            mkdir($fileStorage);
        }
        $this->_blocksFile = $fileStorage . '/blocks.csv';
        $this->_feedFile = $fileStorage . '/feed.csv';
	}

	private function Request(string $url, array $data = [])
    {
		$response = $this->_client->createRequest()
			->setMethod('GET')
			->setUrl($url)
			->setData($data)
            ->setFormat(Client::FORMAT_RAW_URLENCODED)
			->send();

		if ($response->isOk) {
			return $response->data;
		} else {
			throw new \yii\console\Exception('Invalid response');
		}
    }

    public function actionIndex()
    {
    	$feedResponse = $this->request($this->_feedUrl, $this->_feedRequestSettings);

    	$feed = $feedResponse['data'];
    	$csvString = $this->getCsvString($feed, [
    		'_id', 
    		'title',
    	]);
    	$this->write($csvString, $this->_feedFile);

    	$postIds = ArrayHelper::getColumn($feed, '_id');
    	foreach ($postIds as $postId) {
    		$postResponse = $this->request($this->_postStorageUrl . $postId);
    		$postBlocks = ArrayHelper::getValue($postResponse, 'data.blocks');
    		$postCompiledBlocks = ArrayHelper::getColumn($postBlocks, 'compiled');
    		$this->_blocks[$postId] = $this->getCsvString($postCompiledBlocks, [
	    		'_id', 
	    		'type',
	    		'content',
	    	], $postId === current($postIds));
	    	
	    	$blocks = implode($this->_blocks);
	    	$this->write($blocks, $this->_blocksFile);
    	}
    }

    private function getCsvString(array $data, array $filters, bool $start = true): string
    {
    	$csvMemoryHandle = fopen('php://memory', 'b+r');
    	if ($start === true) {
	    	fputs($csvMemoryHandle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
	    	fputcsv($csvMemoryHandle, $filters);
	    }
    	foreach ($data as $dataNode) {
    		if (!is_array($dataNode)) {
    			continue;
    		}
    		$dataColumns = ArrayHelper::filter($dataNode, $filters);
    		array_walk($dataColumns, function(&$data, $filter) {
    			if (in_array($filter, $this->_md5Columns)) {
    				$data = md5(Json::encode($data));
    			}
    		});
    		fputcsv($csvMemoryHandle, $dataColumns);
    	}
    	rewind($csvMemoryHandle);
    	$csvString = stream_get_contents($csvMemoryHandle);
    	fclose($csvMemoryHandle);

    	return $csvString;
    }

    private function write(string $csvString, string $file): void
    {
    	$csvHandle = fopen($file, 'w');
    	fwrite($csvHandle, $csvString);
    	fclose($csvHandle);
    }
}