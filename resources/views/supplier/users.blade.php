@extends('layouts.supplier')
@section('title', 'User Management')
@section('content')
    <div class="user-page" id="userPage">
        <div class="user-card">
            <div class="user-header">
                <div>
                    <h1>User Management</h1>
                    <p>Manage staff accounts, roles, and account access.</p>
                </div>
                <button type="button" class="user-primary-btn" id="addUserBtn"><i class="bi bi-plus-lg"
                        aria-hidden="true"></i> Add New User</button>
            </div>
            <p class="user-notice">Admin account-management preview. Sample changes reset on refresh. Authentication,
                permissions and session invalidation will be enforced by the backend.</p>
            <div class="user-toolbar">
                <div class="user-length"><label for="userLength">Show</label><select id="userLength">
                        <option>5</option>
                        <option>10</option>
                        <option>25</option>
                        <option>50</option>
                    </select><span>entries</span></div>
                <div class="user-search"><label class="visually-hidden" for="userSearch">Search users</label><i
                        class="bi bi-search" aria-hidden="true"></i><input id="userSearch" type="search"
                        placeholder="Search user ID, name or email..." autocomplete="off"></div>
            </div>
            <div class="user-table-container">
                <table id="userTable">
                    <thead>
                        <tr>
                            <th scope="col">User Details</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <p class="user-announcement" id="userAnnouncement" role="status"></p>
        </div>
    </div>

    <div class="modal fade" id="userFormModal" tabindex="-1" aria-labelledby="userFormTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content user-modal">
                <div class="modal-header">
                    <div><span class="user-eyebrow">USER ACCOUNT</span>
                        <h5 class="modal-title" id="userFormTitle">Add New User</h5>
                    </div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="userForm">
                    <div class="modal-body">
                        <div id="userFormError" class="user-error" role="alert" hidden></div><input type="hidden"
                            id="userEditingId">
                        <div class="user-form-grid">
                            <div class="user-field"><label for="userCode">User ID</label><input id="userCode" readonly>
                            </div>
                            <div class="user-field"><label for="userName">Full Name *</label><input id="userName"
                                    maxlength="120" autocomplete="name" required></div>
                            <div class="user-field"><label for="userEmail">Email Address *</label><input id="userEmail"
                                    type="email" maxlength="254" autocomplete="email" required></div>
                            <div class="user-field"><label for="userRole">Role *</label><select id="userRole" required>
                                    <option>Admin</option>
                                    <option>Supplier/Staff</option>
                                    <option>Delivery Personnel</option>
                                </select></div>
                            <div class="user-field"><label for="userStatus">Status</label><select id="userStatus">
                                    <option>Active</option>
                                    <option>Inactive</option>
                                </select></div>
                            <div class="user-field" id="userPasswordField"><label for="userPassword">Temporary Password
                                    *</label><input id="userPassword" type="password" minlength="8" maxlength="128"
                                    autocomplete="new-password"><small>Use a dummy password for this preview. It is not
                                    stored.</small></div>
                        </div>
                        <p class="user-help">Roles use fixed permissions. New accounts and Admin password resets require a
                            password change at the next login in the completed system.</p>
                    </div>
                    <div class="modal-footer"><button type="button" class="user-secondary-btn"
                            data-bs-dismiss="modal">Cancel</button><button type="submit" class="user-primary-btn">Save
                            User</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="userActionModal" tabindex="-1" aria-labelledby="userActionTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content user-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="userActionTitle">Update Account</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="userActionForm">
                    <div class="modal-body">
                        <div id="userActionError" class="user-error" role="alert" hidden></div>
                        <p id="userActionMessage" class="user-help"></p>
                        <div class="user-field" id="userResetField" hidden><label for="userResetPassword">New Temporary
                                Password *</label><input id="userResetPassword" type="password" minlength="8"
                                maxlength="128" autocomplete="new-password"><small>Preview only; the password is never
                                retained or logged.</small></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="user-secondary-btn"
                            data-bs-dismiss="modal">Cancel</button><button type="submit"
                            class="user-primary-btn">Confirm</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="userViewModal" tabindex="-1" aria-labelledby="userViewTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content user-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="userViewTitle">User Details</h5><button type="button" class="btn-close"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="userViewContent"></div>
                <div class="modal-footer"><button type="button" class="user-secondary-btn"
                        data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
@endsection