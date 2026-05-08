<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\Post;
use Nene\Xion\QueryString;
use Nene\Xion\Request;
use PHPUnit\Framework\TestCase;

final class RequestVariablesTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $originalGet;

    /** @var array<string,mixed> */
    private array $originalPost;

    /** @var array<string,mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
        $this->originalServer = $_SERVER;

        if (!defined('LAYERS_NUM')) {
            define('LAYERS_NUM', 0);
        }
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;
        $_SERVER = $this->originalServer;
    }

    public function testPostStoresValuesInBaseRequestStorage(): void
    {
        $_POST = [
            'title' => 'write tests',
            'tags' => ['security', 'boundary'],
        ];

        $post = new Post();

        self::assertTrue($post->has('title'));
        self::assertSame('write tests', $post->get('title'));
        self::assertSame(['security', 'boundary'], $post->get('tags'));
        self::assertSame($_POST, $post->get());
    }

    public function testQueryStringStoresValuesInBaseRequestStorage(): void
    {
        $_GET = [
            'page' => '1',
            'filter' => 'active',
        ];

        $query = new QueryString();

        self::assertTrue($query->has('page'));
        self::assertSame('1', $query->get('page'));
        self::assertSame('active', $query->get('filter'));
        self::assertSame($_GET, $query->get());
    }

    public function testRequestReadsPostQueryAndUrlParametersThroughWrappers(): void
    {
        $_POST = ['title' => 'from post'];
        $_GET = ['q' => 'from query'];
        $_SERVER['REQUEST_URI'] = '/todo/item/id_42';

        $request = new Request();

        self::assertSame('from post', $request->getPost('title'));
        self::assertSame('from query', $request->getQuery('q'));
        self::assertSame('42', $request->getParam('id'));
    }
}
