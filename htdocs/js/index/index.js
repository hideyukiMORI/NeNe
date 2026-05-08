document.addEventListener('DOMContentLoaded', function() {
    const root = document.getElementById('app');
    if (!root || !window.React || !window.ReactDOM) {
        return;
    }

    const e = React.createElement;
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
        const initialTodos = [
            { id: 1, text: 'Read the routing guide', completed: true },
            { id: 2, text: 'Create a controller action', completed: false }
        ];
        const state = useState(false);
        const isSignedIn = state[0];
        const setIsSignedIn = state[1];
        const emailState = useState('demo@example.com');
        const email = emailState[0];
        const setEmail = emailState[1];
        const taskState = useState('');
        const task = taskState[0];
        const setTask = taskState[1];
        const todosState = useState(initialTodos);
        const todos = todosState[0];
        const setTodos = todosState[1];

        function signIn(event) {
            event.preventDefault();
            if (email.trim()) {
                setIsSignedIn(true);
            }
        }

        function addTodo(event) {
            event.preventDefault();
            const text = task.trim();
            if (!text) {
                return;
            }
            setTodos(todos.concat({
                id: Date.now(),
                text: text,
                completed: false
            }));
            setTask('');
        }

        function toggleTodo(id) {
            setTodos(todos.map(function(todo) {
                if (todo.id !== id) {
                    return todo;
                }
                return {
                    id: todo.id,
                    text: todo.text,
                    completed: !todo.completed
                };
            }));
        }

        function removeTodo(id) {
            setTodos(todos.filter(function(todo) {
                return todo.id !== id;
            }));
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
                        task: task,
                        todos: todos,
                        toggleTodo: toggleTodo
                    })
                    : e(LoginForm, {
                        email: email,
                        setEmail: setEmail,
                        signIn: signIn
                    })
            )
        );
    }

    function LoginForm(props) {
        return e('form', { className: 'sample-form', onSubmit: props.signIn },
            e('label', null,
                e('span', null, 'Email'),
                e('input', {
                    type: 'email',
                    value: props.email,
                    onChange: function(event) {
                        props.setEmail(event.target.value);
                    },
                    placeholder: 'demo@example.com'
                })
            ),
            e('label', null,
                e('span', null, 'Password'),
                e('input', {
                    type: 'password',
                    defaultValue: 'password',
                    placeholder: 'password'
                })
            ),
            e('button', { className: 'button button--primary', type: 'submit' }, 'Sign in')
        );
    }

    function TodoPanel(props) {
        return e('div', { className: 'todo-panel' },
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
