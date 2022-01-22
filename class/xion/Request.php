<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 7.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://choosealicense.com/no-permission/ NO LICENSE
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
    final public function getPost(string $key = null)
    {
        if ($key == null) {
            return $this->post->get();
        }
        if ($this->post->has($key) == false) {
            return;
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
    public function getQuery(string $key = null)
    {
        if ($key == null) {
            return $this->query->get();
        }
        if ($this->query->has($key) == false) {
            return;
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
    public function getParam(string $key = null)
    {
        if ($key == null) {
            return $this->param->get();
        }
        if ($this->param->has($key) == false) {
            return;
        }
        return $this->param->get($key);
    }
}
