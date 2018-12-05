<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Authorization\Test\TestCase\Controller\Component;

use Authorization\AuthorizationService;
use Authorization\Controller\Component\AuthorizationComponent;
use Authorization\Exception\ForbiddenException;
use Authorization\Exception\MissingIdentityException;
use Authorization\IdentityDecorator;
use Authorization\Policy\Exception\MissingPolicyException;
use Authorization\Policy\MapResolver;
use Authorization\Policy\OrmResolver;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Datasource\QueryInterface;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use stdClass;
use TestApp\Model\Entity\Article;
use TestApp\Model\Table\ArticlesTable;
use TestApp\Policy\ArticlePolicy;
use TestApp\Policy\ArticlesTablePolicy;
use TestApp\Policy\StringResolver;
use UnexpectedValueException;

/**
 * AuthorizationComponentTest class
 */
class AuthorizationComponentTest extends TestCase
{

    /**
     * setUp method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $service = new AuthorizationService(new OrmResolver());
        $identity = new IdentityDecorator($service, ['id' => 1, 'role' => 'user']);

        $request = new ServerRequest([
            'params' => ['controller' => 'Articles', 'action' => 'edit'],
        ]);
        $request = $request
            ->withAttribute('authorization', $service)
            ->withAttribute('identity', $identity);

        $this->Controller = new Controller($request);
        $this->ComponentRegistry = new ComponentRegistry($this->Controller);
        $this->Auth = new AuthorizationComponent($this->ComponentRegistry);
    }

    public function testNullIdentityForbiddenException()
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Identity is not authorized to perform `view` on `TestApp\Model\Entity\Article`.');

        $service = new AuthorizationService(new OrmResolver());
        $request = new ServerRequest([
            'params' => ['controller' => 'Articles', 'action' => 'view'],
        ]);
        $request = $request
            ->withAttribute('authorization', $service);

        $article = new Article(['visibility' => 'private']);
        $controller = new Controller($request);
        $componentRegistry = new ComponentRegistry($controller);
        $auth = new AuthorizationComponent($componentRegistry);

        $auth->authorize($article);
    }

    public function testNullIdentityAllowed()
    {
        $service = new AuthorizationService(new OrmResolver());
        $request = new ServerRequest([
            'params' => ['controller' => 'Articles', 'action' => 'view'],
        ]);
        $request = $request
            ->withAttribute('authorization', $service);

        $article = new Article(['visibility' => 'public']);
        $controller = new Controller($request);
        $componentRegistry = new ComponentRegistry($controller);
        $auth = new AuthorizationComponent($componentRegistry);

        $this->assertNull($auth->authorize($article));
    }

    public function testAuthorizeUnresolvedPolicy()
    {
        $this->expectException(MissingPolicyException::class);

        $this->Auth->authorize(new stdClass);
    }

    public function testAuthorizeFailedCheck()
    {
        $this->expectException(ForbiddenException::class);

        $article = new Article(['user_id' => 99]);
        $this->Auth->authorize($article);
    }

    public function testAuthorizeFailedCheckStringResolver()
    {
        // Reset the system to use the string resolver
        $service = new AuthorizationService(new StringResolver());
        $identity = new IdentityDecorator($service, ['can_index' => false]);
        $request = new ServerRequest([
            'params' => ['controller' => 'Articles', 'action' => 'index'],
        ]);

        $request = $request
            ->withAttribute('authorization', $service)
            ->withAttribute('identity', $identity);

        $this->Controller = new Controller($request);
        $this->ComponentRegistry = new ComponentRegistry($this->Controller);
        $this->Auth = new AuthorizationComponent($this->ComponentRegistry);

        $this->expectException(ForbiddenException::class);

        $this->Auth->authorize('ArticlesTable');
    }

    public function testAuthorizePolicyException()
    {
        $this->expectException(MissingIdentityException::class);

        $article = new Article(['user_id' => 99]);
        $this->Auth->authorize($article, 'exception');
    }

    public function testAuthorizeSuccessCheckImplicitAction()
    {
        $article = new Article(['user_id' => 1]);
        $this->assertNull($this->Auth->authorize($article));
    }

    public function testAuthorizeSuccessCheckMappedAction()
    {
        $policy = $this->createMock(ArticlePolicy::class);
        $service = new AuthorizationService(new MapResolver([
            Article::class => $policy
        ]));

        $identity = new IdentityDecorator($service, ['can_edit' => true]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $policy->expects($this->never())
            ->method('canEdit');

        $policy->expects($this->once())
            ->method('canModify')
            ->willReturn(true);

        $article = new Article(['user_id' => 1]);

        $this->Auth->setConfig('actionMap', ['edit' => 'modify']);
        $this->assertNull($this->Auth->authorize($article));
    }

    public function testAuthorizeSuccessCheckStringResolver()
    {
        // Reset the system to use the string resolver
        $service = new AuthorizationService(new StringResolver());
        $identity = new IdentityDecorator($service, ['can_index' => true]);
        $request = new ServerRequest([
            'params' => ['controller' => 'Articles', 'action' => 'index'],
        ]);

        $request = $request
            ->withAttribute('authorization', $service)
            ->withAttribute('identity', $identity);

        $this->Controller = new Controller($request);
        $this->ComponentRegistry = new ComponentRegistry($this->Controller);
        $this->Auth = new AuthorizationComponent($this->ComponentRegistry);

        $this->assertNull($this->Auth->authorize('ArticlesTable'));
    }

    public function testApplyScopeImplicitAction()
    {
        $articles = new ArticlesTable();
        $query = $this->createMock(QueryInterface::class);
        $query->method('repository')
            ->willReturn($articles);

        $query->expects($this->once())
            ->method('where')
            ->with([
                'user_id' => 1
            ])
            ->willReturn($query);

        $result = $this->Auth->applyScope($query);

        $this->assertInstanceOf(QueryInterface::class, $result);
        $this->assertSame($query, $result);
    }

    public function testApplyScopeMappedAction()
    {
        $articles = new ArticlesTable();
        $query = $this->createMock(QueryInterface::class);
        $query->method('repository')
            ->willReturn($articles);

        $query->expects($this->once())
            ->method('where')
            ->with([
                'identity_id' => 1
            ])
            ->willReturn($query);

        $this->Auth->setConfig('actionMap', ['edit' => 'modify']);
        $result = $this->Auth->applyScope($query);

        $this->assertInstanceOf(QueryInterface::class, $result);
        $this->assertSame($query, $result);
    }

    public function testApplyScopExplicitAction()
    {
        $articles = new ArticlesTable();
        $query = $this->createMock(QueryInterface::class);
        $query->method('repository')
            ->willReturn($articles);

        $query->expects($this->once())
            ->method('where')
            ->with([
                'identity_id' => 1
            ])
            ->willReturn($query);

        $result = $this->Auth->applyScope($query, 'modify');

        $this->assertInstanceOf(QueryInterface::class, $result);
        $this->assertSame($query, $result);
    }

    public function testAuthorizeSuccessCheckExplicitAction()
    {
        $article = new Article(['user_id' => 1]);
        $this->assertNull($this->Auth->authorize($article, 'edit'));
    }

    public function testAuthorizeBadIdentity()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageRegexp('/Expected that `identity` would be/');

        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', 'derp');

        $article = new Article(['user_id' => 1]);
        $this->Auth->authorize($article);
    }

    public function testAuthorizeModelSuccess()
    {
        $service = new AuthorizationService(new OrmResolver());
        $identity = new IdentityDecorator($service, ['can_edit' => true]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $result = $this->Auth->authorizeAction();
        $this->assertNull($result);
    }

    public function testAuthorizeModelFailure()
    {
        $service = new AuthorizationService(new OrmResolver());
        $identity = new IdentityDecorator($service, ['can_edit' => false]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $this->Auth->setConfig('authorizeModel', ['edit']);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('Identity is not authorized to perform `edit` on `TestApp\Model\Table\ArticlesTable`.');
        $this->Auth->authorizeAction();
    }

    public function testAuthorizeModelAllDisabled()
    {
        $policy = $this->createMock(ArticlesTablePolicy::class);
        $service = new AuthorizationService(new MapResolver([
            ArticlesTable::class => $policy
        ]));

        $identity = new IdentityDecorator($service, ['can_edit' => true]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $policy->expects($this->never())
            ->method('canEdit');

        $result = $this->Auth->authorizeAction();
        $this->assertNull($result);
    }

    public function testAuthorizeModelActionEnabled()
    {
        $service = new AuthorizationService(new OrmResolver());
        $identity = new IdentityDecorator($service, ['can_edit' => true]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $this->Auth->setConfig('authorizeModel', ['edit']);
        $result = $this->Auth->authorizeAction();
        $this->assertNull($result);
    }

    public function testAuthorizeModelMapped()
    {
        $policy = $this->createMock(ArticlesTablePolicy::class);
        $service = new AuthorizationService(new MapResolver([
            ArticlesTable::class => $policy
        ]));

        $identity = new IdentityDecorator($service, ['can_edit' => true]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $policy->expects($this->never())
            ->method('canEdit');

        $policy->expects($this->once())
            ->method('canModify')
            ->willReturn(true);

        $this->Auth->setConfig('authorizeModel', ['edit']);
        $this->Auth->setConfig('actionMap', ['edit' => 'modify']);
        $result = $this->Auth->authorizeAction();
        $this->assertNull($result);
    }

    public function testAuthorizeModelInvalid()
    {
        $service = new AuthorizationService(new OrmResolver());
        $identity = new IdentityDecorator($service, ['can_edit' => true]);
        $this->Controller->request = $this->Controller->request
            ->withAttribute('identity', $identity);

        $this->Auth->setConfig('authorizeModel', ['edit']);
        $this->Auth->setConfig('actionMap', ['edit' => new stdClass]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid action type for `edit`. Expected `string` or `null`, got `stdClass`.');
        $this->Auth->authorizeAction();
    }

    public function testImplementedEvents()
    {
        $events = $this->Auth->implementedEvents();
        $this->assertEquals([
            'Controller.startup' => 'authorizeAction'
        ], $events);
    }

    public function testImplementedCustom()
    {
        $this->Auth->setConfig('authorizationEvent', 'Controller.initialize');
        $events = $this->Auth->implementedEvents();
        $this->assertEquals([
            'Controller.initialize' => 'authorizeAction'
        ], $events);
    }

    public function testSkipAuthorization()
    {
        $service = $this->Controller->request->getAttribute('authorization');
        $this->assertFalse($service->authorizationChecked());

        $this->Auth->skipAuthorization();
        $this->assertTrue($service->authorizationChecked());
    }

    public function testSkipAuthorizationBadService()
    {
        $this->Controller->request = $this->Controller->request
            ->withAttribute('authorization', 'derp');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageRegexp('/Expected that `authorization` would be/');
        $this->Auth->skipAuthorization();
    }

    public function testAuthorizeNotSkipped()
    {
        $service = $this->Controller->request->getAttribute('authorization');

        $this->Auth->authorizeAction();
        $this->assertFalse($service->authorizationChecked());
    }

    public function testAuthorizeActionSkipped()
    {
        $service = $this->Controller->request->getAttribute('authorization');

        $this->Auth->setConfig('skipAuthorization', ['edit']);
        $this->Auth->authorizeAction();
        $this->assertTrue($service->authorizationChecked());
    }

    public function testCan()
    {
        $article = new Article(['user_id' => 1]);
        $this->assertTrue($this->Auth->can($article));
        $this->assertTrue($this->Auth->can($article, 'delete'));

        $article = new Article(['user_id' => 2]);
        $this->assertFalse($this->Auth->can($article));
        $this->assertFalse($this->Auth->can($article, 'delete'));

        $this->assertFalse($this->Auth->can($article, 'exception'));
    }
}
