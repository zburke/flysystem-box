<?php

namespace Zburke\Flysystem\Box;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;

use GuzzleHttp\Client;

use AdammBalogh\Box\Command\Content;
use AdammBalogh\Box\ContentClient;
use AdammBalogh\Box\Request\ExtendedRequest;

use AdammBalogh\Box\Factory\ResponseFactory;
use AdammBalogh\Box\GuzzleHttp\Message\SuccessResponse;
use League\Flysystem\Adapter\Polyfill\StreamedTrait;

use Zburke\Flysystem\Box\CopyFile;

class BoxAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;
    use StreamedTrait;

    /**
     * @var Client
     */
    protected $client;

    /**
     *
     */
    private $tree = [];
    private $paths = ['/' => ['id' => '0', 'type' => 'folder']];

    /**
     * Constructor.
     *
     * @param Client $client
     * @param string $prefix
     */
    public function __construct(ContentClient $client, $prefix = null)
    {
        $this->client = $client;
        $this->setPathPrefix($prefix);
    }



    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $rPath = $this->applyPathPrefix($path);
        if (! $this->has(dirname($path))) {
            $this->createDir(dirname($path), new Config());
        }

        if ($parentFolderId = $this->idForFolder(dirname($rPath))) {
            $command = new Content\File\UploadFile(basename($rPath), $parentFolderId, $contents);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $json = json_decode($response->getBody());
                return [
                    'contents' => $contents,
                    'type' => 'file',
                    'size' => $json->entries[0]->size,
                    'path' => $rPath,
                    'mimetype' => $this->getMimetype($path),
                ];
            }
        }

        return false;
    }


    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        $rPath = $this->applyPathPrefix($path);
        if ($id = $this->idForFile($rPath)) {
            $command = new Content\File\UploadNewFileVersion($id, $contents);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $json = json_decode($response->getBody());
                return [
                    'contents' => $contents,
                    'path' => $rPath,
                    'size' => $json->entries[0]->size,
                    'type' => 'file',
                    'mimetype' => $this->getMimetype($path),
                ];
            }
        }

        return $this->write($path, $contents, $config);
    }



    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        if ($oid = $this->idForFile($path)) {
            $pathDirId = $this->idForFolder(dirname($path));

            if ($newId = $this->idForFolder(dirname($newpath))) {
                $er = new ExtendedRequest();
                $er->setPostBodyField('name', basename($newpath));

                if ($pathDirId !== $newId) {
                    $er->setPostBodyField('parent', (object)['id' => $newId]);
                }

                $command = new Content\File\UpdateFileInfo($oid, $er);
                $response = ResponseFactory::getResponse($this->client, $command);

                if ($response instanceof SuccessResponse) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $command = null;
        $path = $this->applyPathPrefix($path);

        if ($id = $this->idForFile($path)) {
            $newpath = $this->applyPathPrefix($newpath);

            // is newpath just the folder to copy into, or
            // a full path including new filename?
            if ($newId = $this->idForFolder($newpath)) {
                $command = new Content\File\CopyFile($id, $newId);
            }
            elseif ($newId = $this->idForFolder(dirname($newpath))) {
                $command = new CopyFile($id, $newId, basename($newpath));
            }

            if (null !== $command) {
                $response = ResponseFactory::getResponse($this->client, $command);
                if ($response instanceof SuccessResponse) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $path = $this->applyPathPrefix($path);

        if ($id = $this->idForFile($path)) {
            try {
                $command = new Content\File\DeleteFile($id);
                $response = ResponseFactory::getResponse($this->client, $command);
            }
            // on success, box returns a "204 No Conent" header, but that trips
            // up guzzle which expects to have some JSON to parse.
            catch (\GuzzleHttp\Exception\ParseException $pe) {
                $a = explode("\n", $pe->getResponse());
                if (strpos($a[0], "204 No Content")) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($path)
    {
        $path = $this->applyPathPrefix($path);

        if ($id = $this->idForFolder($path)) {
            try {
                $er = new ExtendedRequest();
                $er->addQueryField('recursive', 'true');
                $command = new Content\Folder\DeleteFolder($id, $er);
                $response = ResponseFactory::getResponse($this->client, $command);
            }
            // on success, box returns a "204 No Conent" header, but that trips
            // up guzzle which expects to have some JSON to parse.
            catch (\GuzzleHttp\Exception\ParseException $pe) {
                $a = explode("\n", $pe->getResponse());
                if (strpos($a[0], "204 No Content")) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $hasParent = false;
        $dirname = $this->applyPathPrefix($dirname);

        // folder already exists
        if (false !== $this->idForFolder($dirname)) {
            return false;
        }

        $rPath = '';
        foreach (explode($this->pathSeparator, $dirname) as $part) {
            if (! $part) {
                continue;
            }

            $rPath = "{$rPath}{$this->pathSeparator}{$part}";

            if (false === ($id = $this->idForFolder($rPath))) {
                if ($pid = $this->idForFolder(dirname($rPath))) {
                    $command = new Content\Folder\CreateFolder($part, $pid);
                    $response = ResponseFactory::getResponse($this->client, $command);

                    if (! $response instanceof SuccessResponse) {
                        return false;
                    }
                }
            }
        }

        return true;
    }


    public function read($path)
    {
        $path = $this->applyPathPrefix($path);
        if ($id = $this->idForFile($path)) {
            try {
                $command = new Content\File\DownloadFile($id);
                $response = ResponseFactory::getResponse($this->client, $command);
            }
            // on success, box returns a "302 Found" header, but that trips
            // up guzzle which expects to have some JSON to parse.
            catch (\GuzzleHttp\Exception\ParseException $pe) {
                $a = explode("\n", $pe->getResponse());
                if (strpos($a[0], "302 Found")) {
                    if ($l = $pe->getResponse()->getHeader('Location')) {
                        return (new Client())->get($l)->getBody();
                    }
                }
            }
        }

        return false;
    }



    public function has($path)
    {
        return !! $this->getMetadata($path);
    }



    public function listContents($path = '', $recursive = false)
    {
        $path = $this->applyPathPrefix($path);
        if ($id = $this->idForFolder($path)) {
            $command = new Content\Folder\ListFolder($id);
            $response = ResponseFactory::getResponse($this->client, $command);

            $contents = [];
            if ($response instanceof SuccessResponse) {
                $files = json_decode($response->getBody());
                foreach ($files->entries as $entry) {
                    $contents[] = [
                        'type' => $entry->type,
                        'path' => "{$path}{$entry->name}",
                        'size' => '',
                        'timestamp' => '',
                    ];
                }

                return $contents;
            }
        }

        return false;
    }



    public function getMetadata($path)
    {
        $path = $this->applyPathPrefix($path);
        if ($id = $this->idForFile($path)) {
            $command = new Content\File\GetFileInfo($id);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $info = json_decode($response->getBody());
                return [
                    'basename' => basename($path),
                    'path' => $path,
                    'size' => $info->size,
                    'type' => $info->type,
                    'timestamp' => strtotime($info->modified_at),
                ];
            }
        }
        elseif ($id = $this->idForFolder($path)) {
            $command = new Content\Folder\GetFolderInfo($id);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $info = json_decode($response->getBody());
                return [
                    'basename' => basename($path),
                    'path' => $path,
                    'type' => $info->type,
                    'timestamp' => strtotime($info->modified_at),
                ];
            }
        }

        return false;
    }

    public function getSize($path)
    {
        if ($info = $this->getMetadata($path)) {
            return $info['size'];
        }

        return false;
    }



    public function getMimetype($path)
    {
        if ($this->has($path)) {
            return Util\MimeType::detectByFilename($path);
        }

        return false;
    }


    public function getTimestamp($path)
    {
        if ($info = $this->getMetadata($path)) {
            return $info['timestamp'];
        }

        return false;
    }



    private function idForFile($path)
    {
        return $this->idForPath($path, 'file');
    }

    private function idForFolder($path)
    {
        if ($path !== $this->pathSeparator) {
            $path = rtrim($path, $this->pathSeparator);
        }
        return $this->idForPath($path, 'folder');
    }


    /**
     * Return the ID for the given path, or FALSE.
     * @param string $path
     */
    private function idForPath($path, $type = 'folder')
    {
        // dirname returns "." for an empty path, but we'll use applyPathPrefix
        // to handle relative paths so we just want an empty path.
        if ('.' === $path || '' === $path) {
            $path = $this->pathPrefix;
        }

        if (isset($this->paths[$path]) && $this->paths[$path]['type'] === $type) {
            return $this->paths[$path]['id'];
        }

        $rPath = '';
        $id = 0;
        foreach (explode('/', $path) as $part) {
            if ('/' == $rPath) {
                $rPath = $rPath . $part;
            }
            else {
                $rPath = "{$rPath}/{$part}";
            }

            if (isset($this->paths[$rPath])) {
                if ($rPath === $path && $this->paths[$rPath]['type'] === $type) {
                    return $this->paths[$rPath]['id'];
                }
                else {
                    $this->setPathsForId($this->paths[$rPath]['id'], $rPath == '/' ? '' : $rPath);
                }
            }
        }
        return false;
    }



    private function setPathsForId($id, $path = '')
    {
        $command = new Content\Folder\ListFolder($id);
        $response = ResponseFactory::getResponse($this->client, $command);
        if ($response instanceof SuccessResponse) {
            foreach(json_decode($response->getBody())->entries as $entry) {
                if ('folder' == $entry->type) {
                    $this->paths["{$path}/{$entry->name}"] = [ 'id' => $entry->id, 'type' => 'folder'];
                }
                else {
                    $this->paths["{$path}/{$entry->name}"] = [ 'id' => $entry->id, 'type' => 'file'];
                }
            }
            return true;
        }

        return false;
    }
}
