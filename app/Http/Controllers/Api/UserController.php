<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->latest();

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('active')) {
            $query->where('is_active', (bool) $request->integer('active'));
        }

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));

            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%")
                    ->orWhere('employee_code', 'like', "%{$keyword}%")
                    ->orWhere('job_title', 'like', "%{$keyword}%");
            });
        }

        $result = $query->paginate((int) $request->get('per_page', 20));

        $result->setCollection(
            $result->getCollection()->map(fn ($user) => $this->transformUser($user))
        );

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'phone' => ['nullable', 'string', 'max:30'],
            'employee_code' => ['nullable', 'string', 'max:50', 'unique:users,employee_code'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'leader', 'sale', 'accountant'])],
            'is_active' => ['nullable', 'boolean'],
            'avatar_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $avatarPath = null;
        if ($request->hasFile('avatar_file')) {
            $avatarPath = $this->storeOptimizedAvatar($request->file('avatar_file'));
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'employee_code' => $validated['employee_code'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'department' => $validated['department'] ?? null,
            'gender' => $validated['gender'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'address' => $validated['address'] ?? null,
            'role' => $validated['role'],
            'is_active' => $validated['is_active'] ?? true,
            'avatar' => $avatarPath,
        ]);

        return response()->json($this->transformUser($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'phone' => ['nullable', 'string', 'max:30'],
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_code')->ignore($user->id)],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'leader', 'sale', 'accountant'])],
            'is_active' => ['nullable', 'boolean'],
            'avatar_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('remove_avatar') && $user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->avatar = null;
        }

        if ($request->hasFile('avatar_file')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }

            $user->avatar = $this->storeOptimizedAvatar($request->file('avatar_file'));
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->employee_code = $validated['employee_code'] ?? null;
        $user->job_title = $validated['job_title'] ?? null;
        $user->department = $validated['department'] ?? null;
        $user->gender = $validated['gender'] ?? null;
        $user->date_of_birth = $validated['date_of_birth'] ?? null;
        $user->address = $validated['address'] ?? null;
        $user->role = $validated['role'];
        $user->is_active = $validated['is_active'] ?? true;

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return response()->json($this->transformUser($user->fresh()));
    }

    public function toggleStatus(User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $user->update([
            'is_active' => !$user->is_active,
        ]);

        return response()->json([
            'message' => 'Cập nhật trạng thái thành công.',
            'data' => $this->transformUser($user->fresh()),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json($this->transformUser($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'avatar_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_avatar' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'string'],
            'new_password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ]);

        if ($request->boolean('remove_avatar') && $user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->avatar = null;
        }

        if ($request->hasFile('avatar_file')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }

            $user->avatar = $this->storeOptimizedAvatar($request->file('avatar_file'));
        }

        if (!empty($validated['new_password'])) {
            if (empty($validated['current_password']) || !Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Mật khẩu hiện tại không đúng.',
                ], 422);
            }

            $user->password = Hash::make($validated['new_password']);
        }

        $user->name = $validated['name'];
        $user->phone = $validated['phone'] ?? null;
        $user->job_title = $validated['job_title'] ?? null;
        $user->department = $validated['department'] ?? null;
        $user->gender = $validated['gender'] ?? null;
        $user->date_of_birth = $validated['date_of_birth'] ?? null;
        $user->address = $validated['address'] ?? null;
        $user->save();

        return response()->json($this->transformUser($user->fresh()));
    }

    private function transformUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar ? asset('storage/' . $user->avatar) : null,
            'phone' => $user->phone,
            'employee_code' => $user->employee_code,
            'job_title' => $user->job_title,
            'department' => $user->department,
            'gender' => $user->gender,
            'date_of_birth' => optional($user->date_of_birth)->format('Y-m-d'),
            'address' => $user->address,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'created_at' => optional($user->created_at)->toDateTimeString(),
        ];
    }

    private function storeOptimizedAvatar($file): string
    {
        $imageInfo = getimagesize($file->getRealPath());
        if (!$imageInfo) {
            throw new \RuntimeException('Không đọc được ảnh upload.');
        }

        $mime = $imageInfo['mime'];

        $sourceImage = match ($mime) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($file->getRealPath()),
            'image/png' => imagecreatefrompng($file->getRealPath()),
            'image/webp' => imagecreatefromwebp($file->getRealPath()),
            default => throw new \RuntimeException('Định dạng ảnh không hỗ trợ.'),
        };

        if (!$sourceImage) {
            throw new \RuntimeException('Không tạo được resource ảnh.');
        }

        $srcWidth = imagesx($sourceImage);
        $srcHeight = imagesy($sourceImage);

        $squareSize = min($srcWidth, $srcHeight);
        $srcX = (int) floor(($srcWidth - $squareSize) / 2);
        $srcY = (int) floor(($srcHeight - $squareSize) / 2);

        $targetSize = 512;
        $targetImage = imagecreatetruecolor($targetSize, $targetSize);

        $white = imagecolorallocate($targetImage, 255, 255, 255);
        imagefill($targetImage, 0, 0, $white);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            $srcX,
            $srcY,
            $targetSize,
            $targetSize,
            $squareSize,
            $squareSize
        );

        $directory = 'avatars';

        if (!Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $filename = $directory . '/' . Str::uuid() . '.jpg';
        $fullPath = Storage::disk('public')->path($filename);

        $saved = imagejpeg($targetImage, $fullPath, 82);

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (!$saved) {
            throw new \RuntimeException('Không lưu được file avatar: ' . $fullPath);
        }

        return $filename;
    }
    private function ensureAdmin(Request $request): void
    {
        abort_unless(
            $request->user() && $request->user()->role === 'admin',
            403,
            'Bạn không có quyền thực hiện thao tác này.'
        );
    }
}