document.addEventListener('DOMContentLoaded', function() {
    const root = document.getElementById('app');
    if (!root || !window.React || !window.ReactDOM) {
        return;
    }

    const e = React.createElement;
    const useEffect = React.useEffect;
    const useState = React.useState;
    const tagline = root.dataset.tagline || 'A small legacy PHP framework for URL-based applications.';

    const guides = [
        {
            title: 'Routing',
            text: 'Resolve controllers and actions from simple URL segments.'
        },
        {
            title: 'Templates',
            text: 'Render small server pages with focused Smarty templates.'
        },
        {
            title: 'Modernization',
            text: 'Add tests, OpenAPI, and safer conventions without losing the legacy shape.'
        }
    ];

    const sidebarLinks = [
        { href: '#getting-started', label: 'Getting Started' },
        { href: '#quick-start', label: 'Quick Start' },
        { href: '#tutorial', label: 'Tutorial' },
        { href: '#authentication', label: 'Authentication' },
        { href: '#sample-app', label: 'Sample TODO' },
        { href: '#routing-guide', label: 'Routing Guide' },
        { href: '#openapi', label: 'OpenAPI' }
    ];

    function App() {
        return e('div', { className: 'developers' },
            e(SiteHeader),
            e('div', { className: 'developers__layout' },
                e(Sidebar),
                e('main', { className: 'developers__main' },
                    e(Hero),
                    e(GuideGrid),
                    e(QuickStart),
                    e(ServiceTutorial),
                    e(AuthenticationGuide),
                    e(SampleApp),
                    e(RoutingGuide),
                    e(OpenApiGuide)
                )
            )
        );
    }

    function SiteHeader() {
        return e('header', { className: 'developers__header' },
            e('a', { className: 'developers__brand', href: '/' },
                e('span', { className: 'developers__brand-mark' }, 'N'),
                e('span', null, 'Developers')
            ),
            e('nav', { className: 'developers__nav' },
                e('a', { href: 'https://github.com/hideyukiMORI/NeNe' }, 'GitHub'),
                e('a', { href: 'https://github.com/hideyukiMORI/NeNe/tree/main/docs' }, 'Docs'),
                e('a', { href: 'https://github.com/hideyukiMORI/NeNe/issues' }, 'Issues')
            )
        );
    }

    function Sidebar() {
        return e('aside', { className: 'developers__sidebar' },
            e('p', { className: 'developers__sidebar-title' }, 'NeNe'),
            sidebarLinks.map(function(link, index) {
                return e('a', {
                    className: index === 0 ? 'developers__sidebar-link is-active' : 'developers__sidebar-link',
                    href: link.href,
                    key: link.label
                }, link.label);
            })
        );
    }

    function Hero() {
        return e('section', { className: 'developers__hero' },
            e('p', { className: 'developers__eyebrow' }, 'Legacy PHP Framework'),
            e('h1', null, 'NeNe Developers'),
            e('p', { className: 'developers__lead' }, tagline),
            e('div', { className: 'developers__actions' },
                e('a', { className: 'button button--primary', href: 'https://github.com/hideyukiMORI/NeNe' }, 'View repository'),
                e('a', { className: 'button button--secondary', href: 'https://github.com/hideyukiMORI/NeNe/tree/main/docs' }, 'Read the docs')
            )
        );
    }

    function GuideGrid() {
        return e('section', { className: 'developers__section', id: 'getting-started' },
            e('h2', null, 'Getting Started'),
            e('div', { className: 'developers__cards' },
                guides.map(function(guide) {
                    return e('article', { className: 'developers__card', key: guide.title },
                        e('h3', null, guide.title),
                        e('p', null, guide.text)
                    );
                })
            )
        );
    }

    function QuickStart() {
        return e('section', { className: 'developers__section', id: 'quick-start' },
            e('div', { className: 'developers__section-heading' },
                e('div', null,
                    e('p', { className: 'developers__eyebrow' }, 'Quick Start'),
                    e('h2', null, 'Run NeNe locally')
                ),
                e('p', null, 'Clone the repository and start the Docker environment.')
            ),
            e('div', { className: 'quick-start-card' },
                e('pre', null, e('code', null, 'git clone git@github.com:hideyukiMORI/NeNe.git\ncd NeNe\ndocker compose up --build'))
            )
        );
    }

    function ServiceTutorial() {
        const tutorialSteps = [
            {
                number: '01',
                title: 'Add a fixed page',
                text: 'Use an HTML action such as PageController::aboutAction() and place its template under view/source/page/about.tpl.',
                code: 'GET /page/about -> PageController::aboutAction()'
            },
            {
                number: '02',
                title: 'Add a REST endpoint',
                text: 'Use method-specific handlers so everyone writes the same style of JSON endpoint.',
                code: 'POST /article/index -> ArticleController::indexPostRest()'
            },
            {
                number: '03',
                title: 'Store data with a mapper',
                text: 'Keep persistence explicit with a small model and mapper instead of hiding behavior behind a large ORM.',
                code: 'ArticleMapper::create($title, $body)'
            },
            {
                number: '04',
                title: 'Document and test the contract',
                text: 'Add error codes, update OpenAPI, and cover the behavior with unit or HTTP runtime tests.',
                code: 'composer test && NENE_HTTP_BASE_URL=http://localhost:8080 composer test:http'
            }
        ];

        return e('section', { className: 'developers__section tutorial', id: 'tutorial' },
            e('div', { className: 'developers__section-heading' },
                e('div', null,
                    e('p', { className: 'developers__eyebrow' }, 'Tutorial'),
                    e('h2', null, 'Build a small service')
                ),
                e('p', null, 'The docs remain the source of truth, but this page gives the shortest path from page to REST API.')
            ),
            e('div', { className: 'tutorial__intro' },
                e('p', null, 'NeNe keeps the old-school URL and controller shape familiar to CodeIgniter or Zend Framework 1 users, then adds modern safety rails around it: JSON-only REST, OpenAPI, tests, Docker, and explicit error catalogs.'),
                e('a', {
                    className: 'tutorial__doc-link',
                    href: 'https://github.com/hideyukiMORI/NeNe/blob/main/docs/tutorials/building-a-service.md'
                }, 'Read the full Markdown tutorial')
            ),
            e('div', { className: 'tutorial__steps' },
                tutorialSteps.map(function(step) {
                    return e('article', { className: 'tutorial-step', key: step.number },
                        e('div', { className: 'tutorial-step__meta' },
                            e('span', null, step.number),
                            e('h3', null, step.title)
                        ),
                        e('p', null, step.text),
                        e('code', null, step.code)
                    );
                })
            )
        );
    }

    function AuthenticationGuide() {
        const authItems = [
            {
                title: 'Sample account',
                text: 'The local seed creates admin / admin for development. The password is stored as a hash in MySQL or SQLite seed data.'
            },
            {
                title: 'Session cookie',
                text: 'Successful login creates the PHP session and returns the signed-in user in the shared JSON envelope.'
            },
            {
                title: 'CSRF token',
                text: 'Login also returns Data.csrfToken. State-changing REST requests send it as X-CSRF-Token.'
            }
        ];

        return e('section', { className: 'developers__section', id: 'authentication' },
            e('div', { className: 'developers__section-heading' },
                e('div', null,
                    e('p', { className: 'developers__eyebrow' }, 'Authentication'),
                    e('h2', null, 'Session and CSRF basics')
                ),
                e('p', null, 'The sample app shows the minimum cookie-based authentication flow used by NeNe REST endpoints.')
            ),
            e('div', { className: 'developers__cards' },
                authItems.map(function(item) {
                    return e('article', { className: 'developers__card', key: item.title },
                        e('h3', null, item.title),
                        e('p', null, item.text)
                    );
                })
            )
        );
    }

    function SampleApp() {
        const state = useState(false);
        const isSignedIn = state[0];
        const setIsSignedIn = state[1];
        const userIdState = useState('admin');
        const userId = userIdState[0];
        const setUserId = userIdState[1];
        const passwordState = useState('admin');
        const password = passwordState[0];
        const setPassword = passwordState[1];
        const taskState = useState('');
        const task = taskState[0];
        const setTask = taskState[1];
        const todosState = useState([]);
        const todos = todosState[0];
        const setTodos = todosState[1];
        const statusState = useState('');
        const status = statusState[0];
        const setStatus = statusState[1];
        const loginErrorState = useState('');
        const loginError = loginErrorState[0];
        const setLoginError = loginErrorState[1];
        const userState = useState(null);
        const user = userState[0];
        const setUser = userState[1];
        const csrfTokenState = useState('');
        const csrfToken = csrfTokenState[0];
        const setCsrfToken = csrfTokenState[1];

        useEffect(function() {
            if (isSignedIn) {
                loadTodos();
            }
        }, [isSignedIn]);

        function requestJson(url, options) {
            const requestOptions = options || {};
            requestOptions.headers = Object.assign({
                'Content-Type': 'application/json'
            }, requestOptions.headers || {});
            if (csrfToken && requestOptions.method && requestOptions.method.toUpperCase() !== 'GET') {
                requestOptions.headers['X-CSRF-Token'] = csrfToken;
            }
            return fetch(url, requestOptions)
                .then(function(response) {
                    return response.json().then(function(body) {
                        if (!response.ok || !body.Result) {
                            throw new Error(
                                body.Error
                                    ? body.Error.ErrorMessage
                                    : 'Request failed.'
                            );
                        }
                        if (body.Data && body.Data.status === 'failure') {
                            throw new Error(body.Data.errorMessage || 'Request failed.');
                        }
                        return body.Data;
                    });
                });
        }

        function normalizeTodo(todo) {
            return {
                id: todo.id,
                text: todo.title,
                completed: todo.is_completed
            };
        }

        function loadTodos() {
            setStatus('Loading TODOs...');
            return requestJson('/todo/index')
                .then(function(data) {
                    setTodos(data.todos.map(normalizeTodo));
                    setStatus('');
                })
                .catch(function(error) {
                    setStatus(error.message);
                });
        }

        function signIn(event) {
            event.preventDefault();
            setLoginError('');
            if (!userId.trim() || !password.trim()) {
                setLoginError('ID and password are required.');
                return;
            }
            requestJson('/session/login', {
                method: 'POST',
                body: JSON.stringify({
                    user_id: userId,
                    user_pass: password
                })
            }).then(function(data) {
                setUser(data.user);
                setCsrfToken(data.csrfToken || '');
                setIsSignedIn(true);
            }).catch(function(error) {
                setLoginError(error.message);
            });
        }

        function addTodo(event) {
            event.preventDefault();
            const text = task.trim();
            if (!text) {
                return;
            }
            setStatus('Creating TODO...');
            requestJson('/todo/index', {
                method: 'POST',
                body: JSON.stringify({ title: text })
            }).then(function(data) {
                setTodos(todos.concat(normalizeTodo(data.todo)));
                setTask('');
                setStatus('');
            }).catch(function(error) {
                setStatus(error.message);
            });
        }

        function toggleTodo(id) {
            const target = todos.find(function(todo) {
                return todo.id === id;
            });
            if (!target) {
                return;
            }
            setStatus('Updating TODO...');
            requestJson('/todo/item/id_' + id, {
                method: 'PUT',
                body: JSON.stringify({ is_completed: !target.completed })
            }).then(function(data) {
                const updatedTodo = normalizeTodo(data.todo);
                setTodos(todos.map(function(todo) {
                    return todo.id === id ? updatedTodo : todo;
                }));
                setStatus('');
            }).catch(function(error) {
                setStatus(error.message);
            });
        }

        function removeTodo(id) {
            setStatus('Deleting TODO...');
            requestJson('/todo/item/id_' + id, {
                method: 'DELETE'
            }).then(function() {
                setTodos(todos.filter(function(todo) {
                    return todo.id !== id;
                }));
                setStatus('');
            }).catch(function(error) {
                setStatus(error.message);
            });
        }

        function signOut() {
            setStatus('Signing out...');
            requestJson('/session/logout', {
                method: 'POST'
            }).then(function() {
                setTodos([]);
                setTask('');
                setStatus('');
                setUser(null);
                setCsrfToken('');
                setIsSignedIn(false);
            }).catch(function(error) {
                setStatus(error.message);
            });
        }

        return e('section', { className: 'developers__section developers__sample', id: 'sample-app' },
            e('div', { className: 'developers__section-heading' },
                e('div', null,
                    e('p', { className: 'developers__eyebrow' }, 'Sample App'),
                    e('h2', null, 'Sign in and manage TODOs')
                ),
                e('p', null, 'A tiny React sample running on the NeNe top page.')
            ),
            e('div', { className: 'sample-card' },
                isSignedIn
                    ? e(TodoPanel, {
                        addTodo: addTodo,
                        removeTodo: removeTodo,
                        setTask: setTask,
                        signOut: signOut,
                        task: task,
                        todos: todos,
                        status: status,
                        toggleTodo: toggleTodo,
                        user: user
                    })
                    : e(LoginForm, {
                        loginError: loginError,
                        password: password,
                        setPassword: setPassword,
                        setUserId: setUserId,
                        signIn: signIn,
                        userId: userId
                    })
            )
        );
    }

    function RoutingGuide() {
        const routes = [
            {
                label: 'HTML action',
                code: '/index/index -> IndexController::indexAction()'
            },
            {
                label: 'REST GET',
                code: 'GET /todo/index -> TodoController::indexGetRest()'
            },
            {
                label: 'REST POST',
                code: 'POST /todo/index -> TodoController::indexPostRest()'
            },
            {
                label: 'REST item',
                code: 'PUT /todo/item/id_1 -> TodoController::itemPutRest()'
            }
        ];

        return e('section', { className: 'developers__section', id: 'routing-guide' },
            e('div', { className: 'developers__section-heading' },
                e('div', null,
                    e('p', { className: 'developers__eyebrow' }, 'Routing Guide'),
                    e('h2', null, 'URL segments choose controllers and methods')
                ),
                e('p', null, 'NeNe keeps the legacy URL convention while REST handlers opt into explicit HTTP method suffixes.')
            ),
            e('div', { className: 'guide-panel' },
                routes.map(function(route) {
                    return e('div', { className: 'guide-panel__row', key: route.label },
                        e('span', null, route.label),
                        e('code', null, route.code)
                    );
                })
            )
        );
    }

    function OpenApiGuide() {
        return e('section', { className: 'developers__section', id: 'openapi' },
            e('div', { className: 'developers__section-heading' },
                e('div', null,
                    e('p', { className: 'developers__eyebrow' }, 'OpenAPI'),
                    e('h2', null, 'Explore the REST contract')
                ),
                e('p', null, 'The committed OpenAPI document describes the current Session and TODO sample endpoints.')
            ),
            e('div', { className: 'openapi-card' },
                e('p', null, 'Use Swagger UI for interactive reading, or review the source contract under docs/api/openapi.yaml. Runtime smoke tests verify that documented operations and observed statuses stay aligned.'),
                e('div', { className: 'developers__actions' },
                    e('a', { className: 'button button--primary', href: '/api-docs/' }, 'Open Swagger UI'),
                    e('a', {
                        className: 'button button--secondary',
                        href: 'https://github.com/hideyukiMORI/NeNe/blob/main/docs/api/openapi.yaml'
                    }, 'View openapi.yaml')
                )
            )
        );
    }

    function LoginForm(props) {
        return e('form', { className: 'sample-form', onSubmit: props.signIn },
            props.loginError ? e('p', { className: 'sample-form__error' }, props.loginError) : null,
            e('label', null,
                e('span', null, 'User ID'),
                e('input', {
                    type: 'text',
                    value: props.userId,
                    onChange: function(event) {
                        props.setUserId(event.target.value);
                    },
                    placeholder: 'admin'
                })
            ),
            e('label', null,
                e('span', null, 'Password'),
                e('input', {
                    type: 'password',
                    value: props.password,
                    onChange: function(event) {
                        props.setPassword(event.target.value);
                    },
                    placeholder: 'admin'
                })
            ),
            e('button', { className: 'button button--primary', type: 'submit' }, 'Sign in')
        );
    }

    function TodoPanel(props) {
        return e('div', { className: 'todo-panel' },
            e('div', { className: 'todo-panel__session' },
                props.user ? e('p', { className: 'todo-panel__user' }, 'Signed in as ' + props.user.user_id) : null,
                e('button', {
                    className: 'todo-panel__logout',
                    type: 'button',
                    onClick: props.signOut
                }, 'Sign out')
            ),
            props.status ? e('p', { className: 'todo-panel__status' }, props.status) : null,
            e('form', { className: 'todo-panel__form', onSubmit: props.addTodo },
                e('input', {
                    value: props.task,
                    onChange: function(event) {
                        props.setTask(event.target.value);
                    },
                    placeholder: 'Add a TODO'
                }),
                e('button', { className: 'button button--secondary', type: 'submit' }, 'Add')
            ),
            e('ul', { className: 'todo-list' },
                props.todos.map(function(todo) {
                    return e('li', {
                        className: todo.completed ? 'todo-list__item is-completed' : 'todo-list__item',
                        key: todo.id
                    },
                        e('button', {
                            className: 'todo-list__check',
                            type: 'button',
                            onClick: function() {
                                props.toggleTodo(todo.id);
                            }
                        }, todo.completed ? '✓' : ''),
                        e('span', null, todo.text),
                        e('button', {
                            className: 'todo-list__remove',
                            type: 'button',
                            onClick: function() {
                                props.removeTodo(todo.id);
                            }
                        }, 'Remove')
                    );
                })
            )
        );
    }

    ReactDOM.createRoot(root).render(e(App));
});
