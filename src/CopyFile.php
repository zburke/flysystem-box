<?php

namespace Zburke\Flysystem\Box;

use AdammBalogh\Box\Command\AbstractCommand;
use AdammBalogh\Box\GuzzleHttp\Message\PostRequest;
use GuzzleHttp\Post\PostBody;

class CopyFile extends AbstractCommand
{
    /**
     * @param string $fileId
     * @param string $folderId
     * @param string $name
     */
    public function __construct($fileId, $folderId, $name = null)
    {
        $postBody = new PostBody();
        $postBody->setField('parent', ['id' => $folderId]);
        if ($name) {
            $postBody->setField('name', $name);
        }

        $this->request = new PostRequest("files/{$fileId}/copy");
        $this->request->setRawJsonBody((array)$postBody->getFields());
    }
}
