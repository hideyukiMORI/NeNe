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
        { href: '#sample-app', label: 'Authentication' },
        { href: '#sample-app', label: 'Sample TODO' },
        { href: '#getting-started', label: 'Routing Guide' },
        { href: '#getting-started', label: 'OpenAPI' }
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
                    e(SampleApp)
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
