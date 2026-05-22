<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Request Class
 *
 * This class manages requests.
 * Implements a method that retains POST, GET, URL parameters
 * and returns the specified request.
 *
 * This class is for backward compatibility.
 * It is recommended to use filter_input for POST and GET.
 *
 * @author      HideyukiMORI
 */
class Request
{
    /**
     * POST
     *
     * @var Post
     */
    private $post;

    /**
     * GET
     *
     * @var QueryString
     */
    private $query;

    /**
     * URI
     *
     * @var UrlParameter
     */
    private $param;    // URI

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->post    = new Post();
        $this->query   = new QueryString();
        $this->param   = new UrlParameter();
    }

    /**
     * Get POST
     * Get the value of $_POST
     *
     * @param string|null $key Parameter name.
     *
     * @return mixed
     */
    final public function getPost(?string $key = null): mixed
    {
        if ($key == null) {
            return $this->post->get();
        }
        if ($this->post->has($key) == false) {
            return null;
        }
        return $this->post->get($key);
    }

    /**
     * Get Query
     * Get the value of $_GET
     *
     * @param string|null $key Parameter name.
     *
     * @return mixed
     */
    public function getQuery(?string $key = null): mixed
    {
        if ($key == null) {
            return $this->query->get();
        }
        if ($this->query->has($key) == false) {
            return null;
        }
        return $this->query->get($key);
    }

    /**
     * Get param
     * Gets the value obtained by parsing the URI parameter.
     *
     * @param string|null $key Parameter name.
     *
     * @return mixed
     */
    public function getParam(?string $key = null): mixed
    {
        if ($key == null) {
            return $this->param->get();
        }
        if ($this->param->has($key) == false) {
            return null;
        }
        return $this->param->get($key);
    }

    /**
     * Return the uploaded file at `$_FILES[$key]` as a typed
     * {@see UploadedFile}, or `null` when the key is absent. Does not
     * itself reject incomplete uploads — the controller calls
     * `validate(...)` on the returned wrapper for that.
     */
    public function getFile(string $key): ?UploadedFile
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return null;
        }
        return new UploadedFile($_FILES[$key]);
    }
}
