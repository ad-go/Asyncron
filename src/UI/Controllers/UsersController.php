<?php

namespace AdGo\Cluster\UI\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Taskbar "Users" menu: AJAX/JSON CRUD table over Shield, superadmin only.
 * Every action re-checks the group (not just the taskbar link visibility)
 * since these are plain POST/GET/DELETE endpoints, reachable directly.
 */
class UsersController extends BaseController
{
    public function index(): string
    {
        // A page view, not a JSON endpoint - matches SettingsController::
        // index()'s own pattern (redirect, not requireSuperadmin()'s JSON
        // 403 below), since a ResponseInterface can't be returned from a
        // method declared to return the rendered view's own string.
        if (! (auth()->user()?->inGroup('superadmin'))) {
            return redirect()->to('dashboard');
        }

        // Not the bare 'Users/index' - see AuthController::logoutView()'s
        // own docblock for why a name with no backslash in it can never
        // resolve outside the consuming app's own (empty) app/Views/.
        return view('\AdGo\Cluster\UI\Views\Users\index', ['user' => auth()->user()] + $this->layoutData());
    }

    public function list(): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        $users = model(UserModel::class)->findAll();
        $rows  = array_map(static function (User $u) {
            return [
                'id'       => $u->id,
                'username' => $u->username,
                'email'    => $u->email,
                'active'   => $u->active,
                // Shield's own Bannable trait - the actual "can this
                // account currently log in" flag Session::attempt() checks.
                // 'active' above is unrelated to login (only meaningful
                // during Shield's own email-confirmation registration
                // flow) - kept as-is since it's already part of this
                // response shape, but 'banned' is the one the Users page
                // now acts on.
                'banned'   => $u->isBanned(),
                'groups'   => $u->getGroups(),
            ];
        }, $users);

        return $this->response->setJSON(['ok' => true, 'users' => $rows]);
    }

    public function show(int $id): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        $u = model(UserModel::class)->find($id);
        if ($u === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }

        return $this->response->setJSON(['ok' => true, 'user' => [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email, 'groups' => $u->getGroups(),
        ]]);
    }

    public function create(): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        // is_unique on username added 2026-08-19: without it, a duplicate
        // username fell through validation and hit Shield's own DB-level
        // unique constraint as a raw exception - a 500/HTML response
        // instead of a clean 422, which is exactly the "looks like nothing
        // happened" failure mode users.js's own save handler was just
        // fixed to surface (a generic network-error message, not this
        // specific one) - catching it here instead gives the real reason.
        $rules = ['username' => 'required|min_length[3]|is_unique[users.username]', 'email' => 'required|valid_email|is_unique[auth_identities.secret]', 'password' => 'required|min_length[8]'];
        if (! $this->validate($rules)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'errors' => $this->validator->getErrors()]);
        }

        $users = model(UserModel::class);
        $user  = new User([
            'username' => $this->request->getPost('username'),
            'active'   => 1,
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => $this->request->getPost('email'),
            'password' => $this->request->getPost('password'),
        ]);
        // 'user' is the base group every account gets, 'superadmin' is
        // layered ON TOP of it (see update()'s own comment on why this
        // additive model replaced a single wipe-and-replace group field).
        $user->addGroup('user');
        if ($this->request->getPost('superadmin')) {
            $user->addGroup('superadmin');
        }

        return $this->response->setJSON(['ok' => true, 'id' => $user->id, 'csrf' => $this->csrfPayload()]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        $users = model(UserModel::class);
        $user  = $users->find($id);
        if ($user === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }

        // Empty email/password means "leave unchanged" - same convention as
        // ProfileController::update(). Only validate the ones actually
        // being changed, so editing just the group/username doesn't force
        // re-entering a password.
        $email    = trim((string) $this->request->getPost('email'));
        $username = trim((string) $this->request->getPost('username'));
        $password = (string) $this->request->getPost('password');
        $rules    = [];
        if ($username !== '' && $username !== $user->username) {
            // Same gap as create()'s own is_unique fix above, for the same
            // reason - excluded by id here (users.id, not user_id - this
            // rule targets the users table directly, unlike the email rule
            // below which targets auth_identities).
            $rules['username'] = "min_length[3]|is_unique[users.username,id,{$user->id}]";
        }
        if ($email !== '') {
            // Excludes by user_id (auth_identities' link back to the users
            // table), not id (that column is the identity row's own PK,
            // unrelated to $user->id) - otherwise editing an existing
            // user's email while keeping it the same would always fail
            // uniqueness against their own row.
            $rules['email'] = "required|valid_email|is_unique[auth_identities.secret,user_id,{$user->id}]";
        }
        if ($password !== '') {
            $rules['password'] = 'required|min_length[8]';
        }
        if ($rules !== [] && ! $this->validate($rules)) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'errors' => $this->validator->getErrors()]);
        }

        if ($username !== '') {
            $user->username = $username;
        }
        $users->save($user);

        // Goes through Shield's own UserIdentityModel + password hasher
        // (not a raw DB write) so this stays consistent with what
        // spark shield:user password / login authentication both expect -
        // same pattern already proven in ProfileController::update().
        if ($email !== '' || $password !== '') {
            $identityModel = new \CodeIgniter\Shield\Models\UserIdentityModel();
            $identity      = $identityModel->getIdentityByType($user, \CodeIgniter\Shield\Authentication\Authenticators\Session::ID_TYPE_EMAIL_PASSWORD);
            if ($identity !== null) {
                $dirty = false;
                if ($email !== '' && $email !== $identity->secret) {
                    $identity->secret = $email;
                    $dirty = true;
                }
                if ($password !== '') {
                    $identity->secret2 = service('passwords')->hash($password);
                    $dirty = true;
                }
                if ($dirty) {
                    $identityModel->save($identity);

                    // Cluster-wide session kill on password change (see
                    // README "How it works", Sessions & SSO). Keyed by
                    // $identity->secret, not the original $email var - if
                    // an admin changes both
                    // email and password for a user in this same request,
                    // that's already the NEW email, which is what
                    // SessionInvalidationFilter will look up on every node
                    // from now on.
                    if ($password !== '' && class_exists(\AdGo\Cluster\Cluster::class)) {
                        (new \AdGo\Cluster\Cluster())->broadcastPasswordChange($identity->secret);
                    }
                }
            }
        }

        // Additive/subtractive toggle on 'superadmin' ONLY - not a
        // wipe-and-replace of every group. Found live 2026-08-20: the old
        // single-select field submitted whichever ONE group the JS had
        // pre-filled the dropdown with (u.groups[0] - see users.js), then
        // this handler removed ALL of the user's existing groups and added
        // back just that one. A superadmin who is ALSO in the base 'user'
        // group (everyone is - see create() above) got silently demoted to
        // 'user'-only the moment they saved ANY field on their own row
        // (email, username, password - the group dropdown didn't even need
        // to be touched), because array order put 'user' before
        // 'superadmin' in $u->getGroups(). 'user' is always left alone
        // here; only 'superadmin' itself is ever added or removed, and
        // only when the checkbox was actually part of this submission.
        if ($this->request->getPost('superadmin') !== null) {
            if ($this->request->getPost('superadmin')) {
                if (! $user->inGroup('superadmin')) {
                    $user->addGroup('superadmin');
                }
            } elseif ($user->inGroup('superadmin')) {
                // Same "cannot act on yourself" family as delete()/ban()
                // above - self-demotion is how the bug this whole block's
                // comment describes was actually discovered live, and with
                // no OTHER way in this UI to grant superadmin back once
                // nobody holds it, blocking it here is cheap insurance
                // against a genuinely unrecoverable state, not just an
                // inconvenience.
                if ($id === auth()->id()) {
                    return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'cannot remove your own superadmin']);
                }
                $user->removeGroup('superadmin');
            }
        }

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        if ($id === auth()->id()) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'cannot delete yourself']);
        }

        // Read the email BEFORE the delete() below removes the row (and its
        // identities with it) - same "cluster-wide session kill" mechanism
        // update() already uses for a password change (see that method's
        // own comment), just triggered by account removal instead. Without
        // this, a deleted user's existing sessions on OTHER nodes stayed
        // alive until each node's own session TTL expired there, even
        // though the account itself was already gone - a real gap between
        // "removed the account" and "removed their access".
        $user  = model(UserModel::class)->find($id);
        $email = $user?->getEmailIdentity()?->secret;

        // purge=true, not Shield's own default soft-delete - the confirm
        // modal this button sits behind already promises "cannot be
        // undone" (see App.confirmDeleteUserBody), so a soft delete here
        // was already a broken promise even before this was caught. Found
        // live 2026-08-21: a soft-deleted row leaves users.username and
        // auth_identities.secret (email) fully intact, invisible in
        // list()'s own findAll() (soft-delete-aware) but NOT invisible to
        // is_unique - that rule queries the raw table directly, with no
        // knowledge of deleted_at - so create()'s own uniqueness checks
        // kept rejecting a brand new signup with the exact same username/
        // email as a user that, from every visible angle, no longer
        // existed.
        //
        // purge=true alone isn't enough on this app's own default driver,
        // though: Shield's identities/remember_tokens/groups_users/
        // permissions_users tables all declare user_id ON DELETE CASCADE
        // (see CreateAuthTables), but CI4's SQLite3 driver leaves foreign
        // keys UNENFORCED by default (a per-connection PRAGMA, not a
        // database-level setting) - so on SQLite (this app's default
        // database.default/cluster driver on every node, see InstallCommand
        // ::ensureDatabaseEnv()) a real DELETE on users still silently left
        // every one of those rows behind, cascade or no cascade. Enabling
        // it JUST for this call is a no-op on MySQL/Postgres, where
        // enforcement is already on - safe regardless of which driver a
        // given node's admin later points database.default at.
        // auth_logins/auth_token_logins deliberately have NO cascade (see
        // that migration's own "do NOT delete... for security audits”
        // comment) and are correctly left untouched by this.
        $db = db_connect();
        $db->enableForeignKeyChecks();
        model(UserModel::class)->delete($id, true);

        // user_profiles (this app's own table, not Shield's) has no
        // foreign key at all (see AddUserProfiles migration - a plain
        // unique key on user_id, no ON DELETE clause), so even a fully
        // enforced cascade above would never touch it - deleted by hand
        // here instead, same "leave nothing orphaned behind" goal as the
        // cascade fix just above.
        model(\AdGo\Cluster\UI\Models\UserProfileModel::class)->where('user_id', $id)->delete();

        if ($email !== null && $email !== '' && class_exists(\AdGo\Cluster\Cluster::class)) {
            (new \AdGo\Cluster\Cluster())->broadcastAccountRemoved($email);
        }

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // Reversible suspension, unlike delete() above - Shield's own built-in
    // Bannable trait (User::ban(), from CodeIgniter\Shield\Traits\Bannable)
    // sets users.status='banned', which Session::attempt() already checks
    // and refuses on its own; no new migration needed; that column ships
    // with Shield's default schema. The broadcast below is what this
    // package adds on top - Shield's own ban only blocks the NEXT login
    // attempt, it doesn't touch a session that's already active elsewhere,
    // same gap password-change/logout/delete already close (see
    // Cluster::broadcastAccountDeactivated()'s own docblock).
    public function ban(int $id): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        if ($id === auth()->id()) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => 'cannot ban yourself']);
        }

        $user = model(UserModel::class)->find($id);
        if ($user === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }

        $user->ban();

        $email = $user->getEmailIdentity()?->secret;
        if ($email !== null && $email !== '' && class_exists(\AdGo\Cluster\Cluster::class)) {
            (new \AdGo\Cluster\Cluster())->broadcastAccountDeactivated($email);
        }

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    // Purely additive/safe (an unbanned account still needs a normal
    // login to actually get back in) - no session-kill counterpart
    // needed the way ban() above has one.
    public function unban(int $id): ResponseInterface
    {
        if ($guard = $this->requireSuperadmin()) {
            return $guard;
        }
        $user = model(UserModel::class)->find($id);
        if ($user === null) {
            return $this->response->setStatusCode(404)->setJSON(['ok' => false]);
        }

        $user->unBan();

        return $this->response->setJSON(['ok' => true, 'csrf' => $this->csrfPayload()]);
    }

    /**
     * @return ResponseInterface|null the 403 JSON response to return
     *                                immediately if not authorized, or
     *                                null to proceed - same {ok: false}
     *                                shape every other AJAX endpoint in
     *                                this app returns for this same
     *                                check, not a 404 HTML page (found
     *                                live 2026-08-21: this used to throw
     *                                PageNotFoundException, the one
     *                                superadmin-gated JSON endpoint in
     *                                the app that didn't, so users.js's
     *                                own fetch().then(r => r.json())
     *                                chain would have hit an HTML body
     *                                where it expected JSON).
     */
    private function requireSuperadmin(): ?ResponseInterface
    {
        if (auth()->user()?->inGroup('superadmin')) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON(['ok' => false]);
    }
}
