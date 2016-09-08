<?php

namespace Zburke\Flysystem\Box;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;



use AdammBalogh\Box\Command\Content;
use AdammBalogh\Box\ContentClient;
use AdammBalogh\Box\Factory\ResponseFactory;
use AdammBalogh\Box\GuzzleHttp\Message\SuccessResponse;
use League\Flysystem\Adapter\Polyfill\StreamedTrait;

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
        $path = $this->applyPathPrefix($path);
        if ($parentFolderId = $this->idForFolder(dirname($path))) {
            $command = new Content\File\UploadFile(basename($path), $parentFolderId, $contents);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $json = json_decode($response->getBody());
                return [
                    'type' => 'file',
                    'modified' => strtotime($json->entries[0]->created_at),
                    'path' => $path,
                    'contents' => $contents,
                    'size' => $json->entries[0]->size,
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
        $path = $this->applyPathPrefix($path);
        if ($id = $this->idForFile($path)) {
            $command = new Content\File\UploadNewFileVersion($id, $contents);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $json = json_decode($response->getBody());
                return [
                    'type' => 'file',
                    'modified' => strtotime($json->entries[0]->modified_at),
                    'path' => $path,
                    'contents' => $contents,
                    'size' => $json->entries[0]->size,
                ];
            }
        }
        else {
            return $this->write($path, $contents, $config);
        }

        return false;
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
            if ($newId = $this->idForFolder(dirname($newpath))) {
                $er = new ExtendedRequest();
                $er->setPostBodyField('name', basename($path));
                $er->setPostBodyField('id', $newId);

                $command = new Content\File\UpdateFileInfo($oid, $er);
                $response = ResponseFactory::getResponse($this->client, $command);

                if ($response instanceof SuccessResponse) {
                    $json = json_decode($response->getBody());
                    return [
                        'type' => 'file',
                        'modified' => strtotime($json->modified_at),
                        'path' => $newpath,
                    ];
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
        $path = $this->applyPathPrefix($path);
        $newpath = $this->applyPathPrefix($newpath);

        if ($id = $this->idForPath($path)) {
            if ($newId = $this->idForPath(dirname($newpath))) {
                $command = new Content\File\CopyFile($id, $newId);
                $response = ResponseFactory::getResponse($this->client, $command);

                if ($response instanceof SuccessResponse) {
                    $json = json_decode($response->getBody());
                    return [
                        'type' => 'file',
                        'modified' => strtotime($json->modified_at),
                        'path' => $newpath,
                    ];
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

                if ($response instanceof SuccessResponse) {
                    return true;
                }
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
    public function deleteDir($dirname)
    {

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
        $dirname = $this->applyPathPrefix($dirname);

        if ($id = $this->idForPath($dirname)) {
            $command = new Content\Folder\CreateFolder('folderName', 'parentFolderId');
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                $json = json_decode($response->getBody());

                return [
                    'type' => 'folder',
                    'modified' => strtotime($json->created_at),
                    'path' => $dirname,
                ];
            }
        }

        return false;
    }


    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {

        return false;
    }

    public function read($path)
    {
        $path = $this->applyPathPrefix($path);
        if ($id = $this->idForPath($path)) {
            $command = new Content\File\DownloadFile($id);
            $response = ResponseFactory::getResponse($this->client, $command);

            if ($response instanceof SuccessResponse) {
                return $response->getBody();
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
        if ($id = $this->idForPath($path)) {
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
                    //@@
                    'type' => $info->type,
                    'path' => $path,
                    //@@
                    'visibility' => 'public',
                    'size' => $info->size,
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
                    //@@
                    'type' => $info->type,
                    'path' => $path,
                    //@@
                    'visibility' => 'public',
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
        return Util\MimeType::detectByFilename($path);
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
        if ('.' === $path) {
            $path = '';
        }

//        $path = $this->applyPathPrefix($path);

        if (isset($this->paths[$path]) && $this->paths[$path]['type'] === $type) {
            return $this->paths[$path]['id'];
        }

        if (! count($this->paths)) {
            $this->setPathsForId(0, '');
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
