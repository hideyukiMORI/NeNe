<?php

namespace Nene\Xion;

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * @author hideyuki MORI
 */

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
    private $_post;     // POST
    private $_query;    // GET
    private $_param;    // URI

    /**
     * CONSTRUCTOR.
     */
    public function __construct()
    {
        $this->_post    = new Post();
        $this->_query   = new QueryString();
        $this->_param   = new UrlParameter();
    }



    /**
     * Get POST
     * Get the value of $_POST
     *
     * @param string|null $key  Parameter name.
     */
    final public function getPost(string $key = null)
    {
        if ($key == null) {
            return $this->_post->get();
        }
        if ($this->_post->has($key) == false) {
            return;
        }
        return $this->_post->get($key);
    }



    /**
     * Get Query
     * Get the value of $_GET
     *
     * @param string|null $key  Parameter name.
     */
    public function getQuery(string $key = null)
    {
        if ($key == null) {
            return $this->_query->get();
        }
        if ($this->_query->has($key) == false) {
            return;
        }
        return $this->_query->get($key);
    }



    /**
     * Get param
     * Gets the value obtained by parsing the URI parameter.
     *
     * @param string|null $key  Parameter name.
     */
    public function getParam(string $key = null)
    {
        if ($key == null) {
            return $this->_param->get();
        }
        if ($this->_param->has($key) == false) {
            return;
        }
        return $this->_param->get($key);
    }
}
