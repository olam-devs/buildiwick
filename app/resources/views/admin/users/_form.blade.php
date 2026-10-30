@if($errors->any())<div class="flash error">Please correct the highlighted account details.</div>@endif
<div class="row2">
  <div class="field"><label for="name">Full name <span class="req">*</span></label><input id="name" name="name" value="{{ old('name', $managedUser?->name) }}" required>@error('name')<div class="field-err">{{ $message }}</div>@enderror</div>
  <div class="field"><label for="email">Email <span class="req">*</span></label><input id="email" name="email" type="email" value="{{ old('email', $managedUser?->email) }}" required>@error('email')<div class="field-err">{{ $message }}</div>@enderror</div>
</div>
<div class="row2">
  <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone', $managedUser?->phone) }}">@error('phone')<div class="field-err">{{ $message }}</div>@enderror</div>
  <div class="field"><label for="role_id">Role <span class="req">*</span></label><select id="role_id" name="role_id" required>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((int) old('role_id', $managedUser?->role_id) === $role->id)>{{ ucwords(str_replace('_', ' ', $role->name)) }}</option>@endforeach</select>@error('role_id')<div class="field-err">{{ $message }}</div>@enderror</div>
</div>
<div class="row2">
  <div class="field"><label for="password">{{ $managedUser ? 'New temporary password' : 'Temporary password' }} @unless($managedUser)<span class="req">*</span>@endunless</label><input id="password" name="password" type="password" @required(! $managedUser)><p class="form-context">Minimum 8 characters. Leave blank when editing to keep the current password.</p>@error('password')<div class="field-err">{{ $message }}</div>@enderror</div>
  <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password"></div>
</div>
<div class="field"><label for="is_active">Account access</label><select id="is_active" name="is_active"><option value="1" @selected((string) old('is_active', (int) ($managedUser?->is_active ?? true)) === '1')>Active</option><option value="0" @selected((string) old('is_active', (int) ($managedUser?->is_active ?? true)) === '0')>Inactive</option></select>@error('is_active')<div class="field-err">{{ $message }}</div>@enderror</div>
@if(\App\Support\Features::recording())@include('admin.users._access')@endif
<div class="estimate-actions"><button class="btn btn-primary" type="submit">{{ $managedUser ? 'Save Changes' : 'Create User' }}</button><a class="btn btn-ghost" href="{{ route('admin.users.index') }}">Cancel</a></div>
