<?php
/**
 * This is a modified version of original AWS SDK PHP file.
 * https://github.com/aws/aws-sdk-php
 */
namespace Api\Test\Multipart;

use Api\CommandInterface;
use Api\Multipart\UploadState;
use Api\Multipart\AbstractUploader;
use Api\ResultInterface;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7;

/**
 * Concrete UploadBuilder for the purposes of the following test.
 */
class TestUploader extends AbstractUploader
{
    public function __construct($client, $source, array $config = [])
    {
        parent::__construct($client, $source, $config + [
            'bucket' => null,
            'key'    => null,
        ]);
    }
    protected function loadUploadWorkflowInfo()
    {
        return [
            'command' => [
                'initiate' => 'CreateMultipartUpload',
                'upload'   => 'UploadPart',
                'complete' => 'CompleteMultipartUpload',
                'abort'    => 'AbortMultipartUpload',
            ],
            'id' => [
                'bucket'    => 'Bucket',
                'key'       => 'Key',
                'upload_id' => 'UploadId',
            ],
            'part_num' => 'PartNumber',
        ];
    }

    protected function determinePartSize()
    {
        return $this->config['part_size'] ?: 2;
    }

    protected function getInitiateParams()
    {
        return [];
    }

    protected function createPart($seekable, $number)
    {
        if ($seekable) {
            $body = Psr7\stream_for(fopen($this->source->getMetadata('uri'), 'r'));
            $body = $this->limitPartStream($body);
        } else {
            $body = Psr7\stream_for($this->source->read($this->state->getPartSize()));
        }

        // Do not create a part if the body size is zero.
        if ($body->getSize() === 0) {
            return false;
        }

        return [
            'PartNumber' => $number,
            'Body'       => $body,
        ];
    }

    protected function handleResult(CommandInterface $command, ResultInterface $result)
    {
        $this->state->markPartAsUploaded($command['PartNumber'], [
            'PartNumber' => $command['PartNumber'],
            'ETag'       => $result['ETag']
        ]);
    }

    protected function getCompleteParams()
    {
        return [
            'MultipartUpload' => [
                'Parts' => $this->state->getUploadedParts()
            ]
        ];
    }
}
