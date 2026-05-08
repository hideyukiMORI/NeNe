{extends file='layout/app.tpl'}
{block name='content'}
                <main class="server-install">
                    <section class="server-install__hero">
                        <p class="server-install__eyebrow">Deployment Guide</p>
                        <h1>Install NeNe after git clone</h1>
                        <p>
                            NeNe can run on Docker for local development, but a real server install only needs the
                            repository, Composer dependencies, Apache rewrite support, and a document root pointed at
                            <code>htdocs/</code>.
                        </p>
                        <div class="server-install__actions">
                            <a href="https://github.com/hideyukiMORI/NeNe/blob/main/docs/deployment/server-install.md">Read full Markdown guide</a>
                            <a href="/">Back to top</a>
                        </div>
                    </section>

                    <section class="server-install__panel server-install__confirmed">
                        <div>
                            <p class="server-install__eyebrow">Confirmed Short Path</p>
                            <h2>Minimum commands</h2>
                            <p>
                                This flow has been confirmed with the public sample site at
                                <a href="https://nene-php.com/" target="_blank" rel="noopener noreferrer">nene-php.com</a>.
                            </p>
                        </div>
                        <pre><code>git clone git@github.com:hideyukiMORI/NeNe.git
cd NeNe
composer install --no-dev --optimize-autoloader
php cli/setupDatabase.php --env=.env --yes</code></pre>
                    </section>

                    <section class="server-install__grid">
                        <article class="server-install__card">
                            <span>01</span>
                            <h2>Point Apache to htdocs</h2>
                            <p>
                                The repository root contains application code and docs. Only <code>htdocs/</code> should
                                be public, because it contains the front controller and public assets.
                            </p>
                            <code>/path/to/NeNe/htdocs</code>
                        </article>

                        <article class="server-install__card">
                            <span>02</span>
                            <h2>Install production dependencies</h2>
                            <p>
                                Run Composer from the repository root. Use <code>--no-dev</code> so test and analysis
                                tools are not installed on the server.
                            </p>
                            <code>composer install --no-dev --optimize-autoloader</code>
                        </article>

                        <article class="server-install__card">
                            <span>03</span>
                            <h2>Set runtime environment</h2>
                            <p>
                                Copy <code>.env.example</code> to <code>.env</code> and edit it in the repository root.
                                Web requests load that file before NeNe initializes configuration.
                            </p>
                            <code>NENE_DB_TYPE=MySQL</code>
                        </article>

                        <article class="server-install__card">
                            <span>04</span>
                            <h2>Prepare the database</h2>
                            <p>
                                Docker initializes MySQL automatically. On a server, run the setup command after editing
                                the environment values. If you intentionally use SQLite3, run the SQLite initializer.
                            </p>
                            <code>MySQL: php cli/setupDatabase.php --env=.env --yes</code>
                            <code>SQLite3: php cli/initSQLite.php</code>
                        </article>
                    </section>

                    <section class="server-install__panel">
                        <p class="server-install__eyebrow">Health Check</p>
                        <h2>Confirm the runtime from the browser</h2>
                        <p>
                            The top page calls <code>/health/index</code> and displays whether the API, database
                            connection, and sample schema are ready. It is a small ping-style check for first installs.
                        </p>
                        <pre><code>{
  "status": "success",
  "healthStatus": "ok",
  "api": true,
  "database": true,
  "schema": true
}</code></pre>
                    </section>

                    <section class="server-install__panel">
                        <p class="server-install__eyebrow">Production Checklist</p>
                        <h2>Before making it public</h2>
                        <ul class="server-install__checklist">
                            <li>Set <code>NENE_APP_ENV=production</code> and <code>NENE_APP_DEBUG=0</code>.</li>
                            <li>Use HTTPS and set <code>NENE_SESSION_SECURE=1</code>.</li>
                            <li>Keep real database passwords outside Git.</li>
                            <li>Expose only <code>htdocs/</code>, not the repository root.</li>
                            <li>Remove or change sample credentials before storing real data.</li>
                            <li>Confirm Apache rewrite rules are active for clean URL routing.</li>
                        </ul>
                    </section>

                    <section class="server-install__panel">
                        <p class="server-install__eyebrow">Troubleshooting</p>
                        <h2>Common hosting issues</h2>
                        <div class="server-install__faq">
                            <div>
                                <h3>Composer says php was not found</h3>
                                <p>
                                    The server shell cannot find the PHP CLI binary. Use the hosting provider's PHP path,
                                    then run Composer through that binary.
                                </p>
                                <code>/path/to/php /path/to/composer install --no-dev --optimize-autoloader</code>
                            </div>
                            <div>
                                <h3>Clean URLs return 404</h3>
                                <p>
                                    Check <code>mod_rewrite</code>, <code>AllowOverride</code>, <code>.htaccess</code>,
                                    and whether the document root points to <code>htdocs/</code>.
                                </p>
                            </div>
                            <div>
                                <h3>The TODO sample cannot login</h3>
                                <p>
                                    Confirm the database settings and make sure the <code>users</code> and
                                    <code>todos</code> tables exist for the configured database. For MySQL, run
                                    <code>php cli/setupDatabase.php --env=.env --yes</code>. For SQLite3, run
                                    <code>php cli/initSQLite.php</code>. SQLite DB files under <code>data/</code> are
                                    generated locally and are not committed. Then check <code>/health/index</code>.
                                </p>
                            </div>
                        </div>
                    </section>
                </main>
{/block}
