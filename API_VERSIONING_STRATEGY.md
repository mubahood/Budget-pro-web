# Budget Pro Web - API Versioning & Maintenance Strategy

**Document Version**: 1.0  
**Date**: November 7, 2025  
**Status**: Implementation Guide

---

## Table of Contents

1. [Overview](#overview)
2. [Current State](#current-state)
3. [Versioning Strategy](#versioning-strategy)
4. [Implementation Plan](#implementation-plan)
5. [Deprecation Policy](#deprecation-policy)
6. [Backward Compatibility](#backward-compatibility)
7. [Migration Guide](#migration-guide)
8. [API Changelog](#api-changelog)

---

## Overview

This document outlines the API versioning strategy for Budget Pro Web to ensure smooth evolution of the API while maintaining backward compatibility and providing clear migration paths for clients.

### Goals

- **Stability**: Existing clients continue working without breaking changes
- **Evolution**: New features can be added without disrupting existing functionality
- **Clarity**: Version numbers clearly communicate compatibility
- **Migration**: Easy upgrade paths for clients

---

## Current State

### Version: 1.0.0 (Unversioned URLs)

**Current API Structure**:
```
/api/auth/login
/api/auth/register
/api/budget-item-create
/api/contribution-records-create
/api/{model}
/api/file-uploading
/api/manifest
/api/stock-items
/api/stock-sub-categories
/api/user
```

**Characteristics**:
- No URL versioning implemented
- Direct endpoint access
- All endpoints in root `/api` namespace
- No version parameter required

### Issues with Current Approach

1. **Breaking Changes Risk**: Updates could break existing clients
2. **No Migration Path**: Difficult to introduce new features
3. **No Deprecation Strategy**: Can't phase out old endpoints gracefully
4. **Client Confusion**: Unclear which API version they're using

---

## Versioning Strategy

### Chosen Approach: URL Path Versioning

**Format**: `/api/v{major}/endpoint`

**Example**:
```
/api/v1/auth/login          # Version 1
/api/v2/auth/login          # Version 2 (future)
```

### Why URL Path Versioning?

✅ **Advantages**:
- Clear and explicit in URLs
- Easy to cache different versions
- Simple routing configuration
- Browser-friendly for testing
- No custom headers required

❌ **Alternatives Considered**:

**Header Versioning** (`Accept: application/vnd.budgetpro.v1+json`)
- Pros: Clean URLs
- Cons: Harder to test, cache complexity

**Query Parameter** (`/api/login?version=1`)
- Pros: Flexible
- Cons: Easily forgotten, messy URLs

---

## Implementation Plan

### Phase 1: Add v1 Namespace (Current Version)

**Timeline**: 2 weeks

**Steps**:

#### Step 1: Create v1 Routes File

Create `routes/api_v1.php`:

```php
<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\ContributionController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\GenericController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

// Authentication (Public)
Route::post('auth/register', [AuthController::class, 'register']);
Route::post('auth/login', [AuthController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    // System
    Route::get('user', [AuthController::class, 'currentUser']);
    Route::get('manifest', [SystemController::class, 'manifest']);
    
    // Budget Management
    Route::post('budget-items', [BudgetController::class, 'store']);
    
    // Contribution Records
    Route::post('contribution-records', [ContributionController::class, 'store']);
    
    // Stock Management
    Route::get('stock-items', [StockController::class, 'searchItems']);
    Route::get('stock-sub-categories', [StockController::class, 'searchSubCategories']);
    
    // Generic CRUD
    Route::get('{model}', [GenericController::class, 'index']);
    Route::post('{model}', [GenericController::class, 'store']);
    
    // File Upload
    Route::post('files', [FileController::class, 'upload']);
});
```

#### Step 2: Register v1 Routes

In `app/Providers/RouteServiceProvider.php`:

```php
public function boot(): void
{
    $this->routes(function () {
        // API v1
        Route::prefix('api/v1')
            ->middleware('api')
            ->group(base_path('routes/api_v1.php'));
        
        // Legacy (backward compatibility)
        Route::prefix('api')
            ->middleware('api')
            ->group(base_path('routes/api.php'));
        
        // Web routes
        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    });
}
```

#### Step 3: Create Versioned Controllers

Create controller structure:
```
app/Http/Controllers/Api/
├── V1/
│   ├── AuthController.php
│   ├── BudgetController.php
│   ├── ContributionController.php
│   ├── StockController.php
│   ├── GenericController.php
│   ├── FileController.php
│   └── SystemController.php
```

Example `AuthController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use App\Models\Utils;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * @api {post} /api/v1/auth/register Register User
     * @apiVersion 1.0.0
     */
    public function register(Request $request)
    {
        // Implementation from ApiController::register
    }
    
    /**
     * @api {post} /api/v1/auth/login Login User
     * @apiVersion 1.0.0
     */
    public function login(Request $request)
    {
        // Implementation from ApiController::login
    }
    
    /**
     * @api {get} /api/v1/user Get Current User
     * @apiVersion 1.0.0
     */
    public function currentUser(Request $request)
    {
        return $request->user();
    }
}
```

#### Step 4: Add Version Middleware

Create `app/Http/Middleware/ApiVersion.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiVersion
{
    public function handle(Request $request, Closure $next, $version)
    {
        // Add version to request
        $request->attributes->set('api_version', $version);
        
        // Add version to response headers
        return $next($request)->header('X-API-Version', $version);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ...
    'api.version' => \App\Http\Middleware\ApiVersion::class,
];
```

Update routes:

```php
Route::prefix('api/v1')
    ->middleware(['api', 'api.version:1'])
    ->group(base_path('routes/api_v1.php'));
```

### Phase 2: Maintain Legacy Support

**Timeline**: Ongoing (12 months minimum)

Keep existing `/api` endpoints working alongside `/api/v1`:

```php
// routes/api.php (legacy)
Route::post('auth/register', [ApiController::class, 'register'])
    ->name('legacy.auth.register');

Route::post('auth/login', [ApiController::class, 'login'])
    ->name('legacy.auth.login');

// Add deprecation warning to responses
Route::middleware('api.deprecated')->group(function () {
    // All existing routes
});
```

Create deprecation middleware:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiDeprecated
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        return $response->header('X-API-Deprecated', 'true')
                       ->header('X-API-Sunset', '2026-11-07')
                       ->header('X-API-Migration', 'https://docs.budget-pro.com/api/v1/migration');
    }
}
```

### Phase 3: Future Version (v2) Planning

**Timeline**: TBD (based on breaking changes needed)

When to create v2:
- Breaking changes to response structure
- Removal of deprecated endpoints
- Major authentication changes
- Database schema breaking changes

---

## Deprecation Policy

### Deprecation Timeline

```
Month 0: Feature marked as deprecated
Month 3: Deprecation warning in responses
Month 6: Documentation updated with alternatives
Month 12: Feature removed (v2 release)
```

### Deprecation Process

#### 1. Announce Deprecation

**In API Response**:
```json
{
  "code": 1,
  "message": "Success",
  "data": {...},
  "deprecated": {
    "version": "1.5.0",
    "endpoint": "/api/budget-item-create",
    "alternative": "/api/v1/budget-items",
    "sunset_date": "2026-11-07",
    "migration_guide": "https://docs.budget-pro.com/migration/budget-items"
  }
}
```

**In Response Headers**:
```
X-API-Deprecated: true
X-API-Sunset: 2026-11-07
X-API-Alternative: /api/v1/budget-items
Warning: 299 - "Deprecated API"
```

#### 2. Update Documentation

- Mark endpoints as **DEPRECATED** in docs
- Add warning banners
- Link to alternatives
- Provide migration examples

#### 3. Notify Clients

- Email notifications to registered API users
- In-app notifications
- Changelog updates
- Blog post announcement

#### 4. Monitor Usage

Track deprecated endpoint usage:

```php
Log::info('Deprecated API Used', [
    'endpoint' => $request->path(),
    'user_id' => $request->user()?->id,
    'ip' => $request->ip(),
    'user_agent' => $request->userAgent(),
]);
```

#### 5. Remove After Sunset

Only remove after:
- ✅ Minimum 12 months notice
- ✅ Usage below 5% of total API calls
- ✅ All known clients migrated
- ✅ Alternative endpoint stable

---

## Backward Compatibility

### Guaranteed Compatibility Within Major Version

**v1.x.x Changes That Are Safe**:
- ✅ Adding new optional fields
- ✅ Adding new endpoints
- ✅ Adding new response fields
- ✅ Adding new query parameters (optional)
- ✅ Improving error messages
- ✅ Performance improvements

**Breaking Changes (Require v2)**:
- ❌ Removing endpoints
- ❌ Removing response fields
- ❌ Changing field types
- ❌ Changing authentication method
- ❌ Making optional fields required
- ❌ Changing response structure

### Semantic Versioning

Format: `MAJOR.MINOR.PATCH`

**Examples**:
- `1.0.0` → `1.0.1`: Bug fixes, no API changes
- `1.0.0` → `1.1.0`: New features, backward compatible
- `1.0.0` → `2.0.0`: Breaking changes

### Version Support Policy

| Version | Support Type | Duration |
|---------|-------------|----------|
| Current Major (v1) | Full Support | Indefinite |
| Previous Major (v0/legacy) | Security Only | 12 months |
| Older Versions | None | Deprecated |

---

## Migration Guide

### From Legacy to v1

#### Step 1: Update Base URL

**Before**:
```javascript
const API_BASE = 'https://api.budget-pro.com/api';
```

**After**:
```javascript
const API_BASE = 'https://api.budget-pro.com/api/v1';
```

#### Step 2: Update Endpoint Paths

| Legacy Endpoint | v1 Endpoint | Notes |
|----------------|-------------|-------|
| `/api/auth/login` | `/api/v1/auth/login` | Same payload |
| `/api/auth/register` | `/api/v1/auth/register` | Same payload |
| `/api/budget-item-create` | `/api/v1/budget-items` | RESTful naming |
| `/api/contribution-records-create` | `/api/v1/contribution-records` | RESTful naming |
| `/api/file-uploading` | `/api/v1/files` | RESTful naming |
| `/api/api/{model}` | `/api/v1/{model}` | Same functionality |

#### Step 3: Update Authentication

**No changes required** - Bearer token authentication remains the same.

#### Step 4: Test Thoroughly

```bash
# Test v1 endpoints
curl -X POST https://api.budget-pro.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"pass123"}'

# Verify responses match expected format
```

#### Step 5: Monitor for Issues

- Check response headers for version confirmation
- Log any errors during migration
- Monitor API usage metrics

### Example Migration Code

**JavaScript/TypeScript**:

```typescript
// Before
class BudgetProAPI {
  private baseURL = 'https://api.budget-pro.com/api';
  
  async createBudgetItem(data: BudgetItemData) {
    return fetch(`${this.baseURL}/budget-item-create`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    });
  }
}

// After
class BudgetProAPI {
  private baseURL = 'https://api.budget-pro.com/api/v1';
  
  async createBudgetItem(data: BudgetItemData) {
    return fetch(`${this.baseURL}/budget-items`, {  // RESTful naming
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(data)
    });
  }
}
```

**Flutter/Dart**:

```dart
// Before
class BudgetProAPI {
  static const String baseUrl = 'https://api.budget-pro.com/api';
  
  Future<Response> createBudgetItem(Map<String, dynamic> data) async {
    return await dio.post(
      '$baseUrl/budget-item-create',
      data: data,
      options: Options(headers: {'Authorization': 'Bearer $token'}),
    );
  }
}

// After
class BudgetProAPI {
  static const String baseUrl = 'https://api.budget-pro.com/api/v1';
  
  Future<Response> createBudgetItem(Map<String, dynamic> data) async {
    return await dio.post(
      '$baseUrl/budget-items',  // RESTful naming
      data: data,
      options: Options(headers: {'Authorization': 'Bearer $token'}),
    );
  }
}
```

---

## API Changelog

### Version 1.0.0 (Current - November 2025)

**Initial Release**

**Endpoints**:
- Authentication (register, login)
- Budget management (create/update budget items)
- Contribution records (create/update)
- Stock management (search items, sub-categories)
- Generic CRUD operations
- File uploads
- System (manifest, user info)

**Features**:
- Bearer token authentication
- Company-based data isolation
- File upload support
- Generic model CRUD

---

### Future Version 1.1.0 (Planned - Q1 2026)

**New Features** (backward compatible):
- Bulk operations endpoints
- Export to Excel/PDF
- Advanced filtering
- Pagination support
- Webhook notifications

**Improvements**:
- Better error messages
- Response time optimization
- Additional validation

---

### Future Version 2.0.0 (Planned - Q4 2026)

**Breaking Changes**:
- RESTful endpoint naming throughout
- Standardized response envelopes
- ISO 8601 date formats
- Pagination required for list endpoints
- Remove deprecated legacy endpoints

---

## Best Practices for API Consumers

### 1. Always Use Versioned URLs

```javascript
// ✅ Good
const url = 'https://api.budget-pro.com/api/v1/auth/login';

// ❌ Bad
const url = 'https://api.budget-pro.com/api/auth/login';
```

### 2. Check Response Headers

```javascript
const response = await fetch(url);
const apiVersion = response.headers.get('X-API-Version');
const isDeprecated = response.headers.get('X-API-Deprecated');

if (isDeprecated === 'true') {
  console.warn('Using deprecated API endpoint');
  const alternative = response.headers.get('X-API-Alternative');
  console.log(`Migrate to: ${alternative}`);
}
```

### 3. Handle Version Mismatches

```javascript
if (apiVersion !== '1') {
  throw new Error('API version mismatch');
}
```

### 4. Subscribe to API Updates

- Monitor API changelog
- Join API mailing list
- Set up webhook notifications
- Follow deprecation notices

---

## Implementation Checklist

### Phase 1: Setup (Week 1-2)

- [ ] Create `routes/api_v1.php`
- [ ] Create versioned controller structure
- [ ] Implement `ApiVersion` middleware
- [ ] Update `RouteServiceProvider`
- [ ] Test all v1 endpoints

### Phase 2: Migration (Week 3-4)

- [ ] Update API documentation
- [ ] Update Postman collection
- [ ] Create migration guide
- [ ] Notify existing API consumers
- [ ] Deploy v1 to staging

### Phase 3: Legacy Support (Week 5-6)

- [ ] Implement `ApiDeprecated` middleware
- [ ] Add deprecation headers
- [ ] Set up usage tracking
- [ ] Create sunset timeline
- [ ] Deploy to production

### Phase 4: Monitoring (Ongoing)

- [ ] Monitor API usage by version
- [ ] Track deprecated endpoint usage
- [ ] Collect client feedback
- [ ] Plan v1.1 features
- [ ] Review migration progress

---

## Conclusion

This versioning strategy provides:
- ✅ Clear migration path from legacy to v1
- ✅ Backward compatibility guarantees
- ✅ Graceful deprecation process
- ✅ Future-proof architecture
- ✅ Developer-friendly approach

**Next Steps**:
1. Review and approve this strategy
2. Begin Phase 1 implementation
3. Update client applications
4. Monitor and iterate

---

**Document Owner**: API Team  
**Last Updated**: November 7, 2025  
**Next Review**: February 2026
