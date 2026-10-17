{{-- "Sign in as" (App\Admin\Controllers\SignInAsController): open one of this company's accounts in the web portal with the master password. --}}
<div class="row">
    <div class="col-md-8 col-lg-6">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Open an account of {{ $company->name }}</h3>
            </div>
            <div class="box-body">
                <p>You land in the web portal as this person. The session ends after {{ $minutes }} minutes, and the screen says so the whole time. Who you are, the account and your reason are recorded, and the owner is told.</p>

                @if(! $portal)
                    <div class="alert alert-warning">This server has no web portal address (SAAS_NEW_UI_URL), so Sign in as has nowhere to open the account.</div>
                @elseif(! $configured)
                    <div class="alert alert-warning">The master password is not set up on this server. Make its hash with <code>php artisan master-password:set</code>.</div>
                @elseif(! $ipAllowed)
                    <div class="alert alert-warning">The master password does not work from your address ({{ $ip }}). Only the addresses in MASTER_PASSWORD_ALLOWED_IPS may use it.</div>
                @endif

                @if($errors->any())
                    <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="post" action="{{ admin_url('companies/'.$company->id.'/sign-in-as') }}" autocomplete="off">
                    @csrf
                    <div class="form-group @if($errors->has('user_id')) has-error @endif">
                        <label for="sia-user">Account to open</label>
                        <select id="sia-user" name="user_id" class="form-control" required>
                            @foreach($accounts as $id => $label)
                                <option value="{{ $id }}" @selected((string) old('user_id') === (string) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group @if($errors->has('reason')) has-error @endif">
                        <label for="sia-reason">Why are you opening it?</label>
                        <textarea id="sia-reason" name="reason" class="form-control" rows="3" minlength="{{ $minReason }}" maxlength="500" required
                            placeholder="For example: the owner asked on WhatsApp for help with a stock count">{{ old('reason') }}</textarea>
                        <p class="help-block">{{ $minReason }} characters or more. The owner sees it.</p>
                    </div>
                    <div class="form-group @if($errors->has('master_password')) has-error @endif">
                        <label for="sia-master">Master password</label>
                        <input id="sia-master" type="password" name="master_password" class="form-control" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-warning" @disabled(! $portal || ! $configured || $accounts === [])>
                        <i class="fa fa-user-secret"></i> Sign in as this person
                    </button>
                    <a href="{{ admin_url('companies') }}" class="btn btn-default">Back to companies</a>
                </form>
            </div>
        </div>
    </div>
</div>
