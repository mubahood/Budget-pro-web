# Budget Pro Web - API Documentation

**Version**: 1.0.0  
**Base URL**: `{your-domain}/api`  
**Date**: November 7, 2025

---

## Table of Contents

1. [Authentication](#authentication)
2. [API Endpoints](#api-endpoints)
   - [Authentication Endpoints](#authentication-endpoints)
   - [Budget Management](#budget-management)
   - [Contribution Records](#contribution-records)
   - [Stock Management](#stock-management)
   - [Generic CRUD Operations](#generic-crud-operations)
   - [File Management](#file-management)
   - [System](#system)
3. [Response Format](#response-format)
4. [Error Codes](#error-codes)
5. [Authentication Flow](#authentication-flow)
6. [Request Examples](#request-examples)

---

## Authentication

Budget Pro Web API uses **Bearer Token Authentication** with Laravel Sanctum.

### Authentication Header

All authenticated endpoints require the following header:

```http
Authorization: Bearer {token}
```

### How to Get Token

1. Register a new account using `/auth/register`
2. Login using `/auth/login`
3. Use the user object's token in subsequent requests

---

## API Endpoints

### Authentication Endpoints

#### 1. Register New User

**Endpoint**: `POST /auth/register`

**Description**: Creates a new user account and company.

**Authentication**: None (Public)

**Request Body**:

```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe@example.com",
  "password": "secure_password123",
  "phone_number": "+256700000000",
  "company_name": "Acme Corporation",
  "currency": "UGX"
}
```

**Required Fields**:
- `first_name` (string) - User's first name
- `last_name` (string) - User's last name
- `email` (string, valid email) - User's email address
- `password` (string) - User's password
- `company_name` (string) - Name of the company
- `currency` (string) - Currency code (e.g., UGX, USD, EUR)

**Optional Fields**:
- `phone_number` (string) - User's phone number

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Registration successful.",
  "data": {
    "user": {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "username": "john.doe@example.com",
      "email": "john.doe@example.com",
      "phone_number": "+256700000000",
      "company_id": 1,
      "status": "Active",
      "created_at": "2025-11-07T10:00:00.000000Z",
      "updated_at": "2025-11-07T10:00:00.000000Z"
    },
    "company": {
      "id": 1,
      "owner_id": 1,
      "name": "Acme Corporation",
      "email": "john.doe@example.com",
      "phone_number": "+256700000000",
      "status": "Active",
      "currency": "UGX",
      "license_expire": "2026-11-07",
      "created_at": "2025-11-07T10:00:00.000000Z",
      "updated_at": "2025-11-07T10:00:00.000000Z"
    }
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Email is already registered.",
  "data": null
}
```

**Possible Errors**:
- "First name is required."
- "Last name is required."
- "Email is required."
- "Email is invalid."
- "Email is already registered."
- "Password is required."
- "Company name is required."
- "Currency is required."

---

#### 2. Login

**Endpoint**: `POST /auth/login`

**Description**: Authenticates a user and returns user and company data.

**Authentication**: None (Public)

**Request Body**:

```json
{
  "email": "john.doe@example.com",
  "password": "secure_password123"
}
```

**Required Fields**:
- `email` (string) - User's email address
- `password` (string) - User's password

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe@example.com",
      "company_id": 1,
      "status": "Active",
      "token": "1|abc123xyz..."
    },
    "company": {
      "id": 1,
      "name": "Acme Corporation",
      "currency": "UGX",
      "status": "Active"
    }
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Invalid password.",
  "data": null
}
```

**Possible Errors**:
- "Email is required."
- "Password is required."
- "Account not found."
- "Invalid password."
- "Company not found."

---

### Budget Management

#### 3. Create/Update Budget Item

**Endpoint**: `POST /budget-item-create`

**Description**: Creates a new budget item or updates an existing one.

**Authentication**: Required (Bearer Token)

**Request Body**:

```json
{
  "id": null,
  "budget_program_id": 1,
  "budget_item_category_id": 1,
  "financial_period_id": 1,
  "name": "Office Supplies",
  "description": "Monthly office supplies budget",
  "amount": 500000,
  "quantity": 1,
  "total": 500000,
  "status": "Pending",
  "date": "2025-11-07"
}
```

**Required Fields**:
- `budget_program_id` (integer) - ID of the budget program
- `budget_item_category_id` (integer) - ID of the budget category
- `financial_period_id` (integer) - ID of the financial period
- `name` (string) - Name of the budget item
- `amount` (decimal) - Unit amount
- `quantity` (integer) - Quantity
- `total` (decimal) - Total amount (amount × quantity)
- `status` (string) - Status: Pending, Approved, Rejected
- `date` (date) - Budget date

**Optional Fields**:
- `id` (integer) - For updating existing item (null for new)
- `description` (text) - Item description
- `payment_date` (date) - Payment date
- `payment_method` (string) - Payment method

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Created successfully.",
  "data": {
    "id": 1,
    "company_id": 1,
    "budget_program_id": 1,
    "budget_item_category_id": 1,
    "financial_period_id": 1,
    "name": "Office Supplies",
    "description": "Monthly office supplies budget",
    "amount": 500000,
    "quantity": 1,
    "total": 500000,
    "status": "Pending",
    "date": "2025-11-07",
    "created_at": "2025-11-07T10:00:00.000000Z",
    "updated_at": "2025-11-07T10:00:00.000000Z"
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Failed to save.",
  "data": null
}
```

---

### Contribution Records

#### 4. Create/Update Contribution Record

**Endpoint**: `POST /contribution-records-create`

**Description**: Creates or updates a member contribution record.

**Authentication**: Required (Bearer Token)

**Request Body**:

```json
{
  "id": null,
  "member_id": 5,
  "treasurer_id": 2,
  "financial_period_id": 1,
  "amount": 100000,
  "payment_method": "Cash",
  "description": "Monthly contribution",
  "date": "2025-11-07"
}
```

**Required Fields**:
- `member_id` (integer) - ID of the contributing member
- `treasurer_id` (integer) - ID of the treasurer receiving payment
- `financial_period_id` (integer) - ID of the financial period
- `amount` (decimal) - Contribution amount
- `date` (date) - Contribution date

**Optional Fields**:
- `id` (integer) - For updating existing record (null for new)
- `payment_method` (string) - Payment method (Cash, Mobile Money, Bank)
- `description` (text) - Additional notes

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Created successfully.",
  "data": {
    "id": 1,
    "company_id": 1,
    "member_id": 5,
    "treasurer_id": 2,
    "financial_period_id": 1,
    "amount": 100000,
    "payment_method": "Cash",
    "description": "Monthly contribution",
    "date": "2025-11-07",
    "created_at": "2025-11-07T10:00:00.000000Z",
    "updated_at": "2025-11-07T10:00:00.000000Z"
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Treasurer is required.",
  "data": null
}
```

**Possible Errors**:
- "Treasurer is required."
- "Treasurer not found."
- "Failed to save."

---

### Stock Management

#### 5. Search Stock Items

**Endpoint**: `GET /stock-items`

**Description**: Search for stock items by name or SKU.

**Authentication**: None (Public)

**Query Parameters**:
- `q` (string) - Search query
- `company_id` (integer, required) - Company ID

**Example Request**:

```
GET /stock-items?q=laptop&company_id=1
```

**Success Response** (200):

```json
{
  "data": [
    {
      "id": 1,
      "text": "LAP001 Dell Laptop XPS 15"
    },
    {
      "id": 2,
      "text": "LAP002 HP Laptop EliteBook"
    }
  ]
}
```

**Error Response** (400):

```json
{
  "data": []
}
```

**Notes**:
- Returns max 20 results
- Results ordered alphabetically by name
- Company ID is required

---

#### 6. Search Stock Sub-Categories

**Endpoint**: `GET /stock-sub-categories`

**Description**: Search for stock sub-categories.

**Authentication**: None (Public)

**Query Parameters**:
- `q` (string) - Search query
- `company_id` (integer, required) - Company ID

**Example Request**:

```
GET /stock-sub-categories?q=electronics&company_id=1
```

**Success Response** (200):

```json
{
  "data": [
    {
      "id": 1,
      "text": "Electronics - Computers (pieces)"
    },
    {
      "id": 2,
      "text": "Electronics - Accessories (pieces)"
    }
  ]
}
```

**Error Response** (400):

```json
{
  "data": []
}
```

---

### Generic CRUD Operations

#### 7. List Records (Generic)

**Endpoint**: `GET /api/{model}`

**Description**: Retrieves all records for a specific model filtered by company.

**Authentication**: Required (Bearer Token)

**Path Parameters**:
- `model` (string) - Model name (e.g., BudgetProgram, FinancialCategory)

**Available Models**:
- `BudgetProgram`
- `BudgetItemCategory`
- `FinancialPeriod`
- `FinancialCategory`
- `StockCategory`
- `StockSubCategory`
- `StockItem`
- `User`

**Example Request**:

```
GET /api/BudgetProgram
Authorization: Bearer {token}
```

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Listed successfully.",
  "data": [
    {
      "id": 1,
      "company_id": 1,
      "name": "Annual Budget 2025",
      "status": "Active",
      "created_at": "2025-01-01T00:00:00.000000Z",
      "updated_at": "2025-01-01T00:00:00.000000Z"
    }
  ]
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Unauthonticated.",
  "data": null
}
```

**Notes**:
- Returns up to 100,000 records
- Automatically filtered by user's company
- Only returns records belonging to authenticated user's company

---

#### 8. Create/Update Record (Generic)

**Endpoint**: `POST /api/{model}`

**Description**: Creates or updates a record for any model.

**Authentication**: Required (Bearer Token)

**Path Parameters**:
- `model` (string) - Model name

**Request Body**:

```json
{
  "id": null,
  "name": "New Budget Program",
  "status": "Active",
  "description": "Program description",
  "temp_file_field": "photo"
}
```

**Common Fields**:
- `id` (integer) - For updates (null for new records)
- `temp_file_field` (string) - Name of image field for file upload
- Additional fields depend on the specific model

**File Upload**:
When uploading files (e.g., images):
1. Set `temp_file_field` to the field name (e.g., "photo")
2. Include file in multipart/form-data as `photo`

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Created successfully.",
  "data": {
    "id": 1,
    "company_id": 1,
    "name": "New Budget Program",
    "status": "Active",
    "description": "Program description",
    "created_at": "2025-11-07T10:00:00.000000Z",
    "updated_at": "2025-11-07T10:00:00.000000Z"
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Database error message",
  "data": null
}
```

---

### File Management

#### 9. Upload File

**Endpoint**: `POST /file-uploading`

**Description**: Uploads a file to the server.

**Authentication**: Required (Bearer Token)

**Content-Type**: `multipart/form-data`

**Request Body**:
- `photo` (file) - The file to upload

**Example Request** (cURL):

```bash
curl -X POST \
  'https://yourdomain.com/api/file-uploading' \
  -H 'Authorization: Bearer {token}' \
  -F 'photo=@/path/to/file.jpg'
```

**Success Response** (200):

```json
{
  "code": 1,
  "message": "File uploaded successfully.",
  "data": {
    "file_name": "uploads/2025/11/07/abc123xyz.jpg"
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "File not uploaded.",
  "data": null
}
```

**Notes**:
- Returns the relative path to the uploaded file
- Use this path when creating/updating records with image fields
- File is stored in the public storage directory

---

### System

#### 10. Get Manifest

**Endpoint**: `GET /manifest`

**Description**: Returns application metadata and user context.

**Authentication**: Required (Bearer Token)

**Success Response** (200):

```json
{
  "code": 1,
  "message": "Success.",
  "data": {
    "name": "Invetor-Track",
    "short_name": "IT",
    "description": "Inventory Management System",
    "version": "1.0.0",
    "author": "M. Muhido",
    "user": {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe@example.com",
      "company_id": 1,
      "status": "Active"
    },
    "roles": [
      {
        "user_id": 1,
        "role_id": 2
      }
    ],
    "company": {
      "id": 1,
      "name": "Acme Corporation",
      "currency": "UGX",
      "status": "Active",
      "license_expire": "2026-11-07"
    }
  }
}
```

**Error Response** (400):

```json
{
  "code": 0,
  "message": "Unauthonticated.",
  "data": null
}
```

**Use Cases**:
- App initialization
- User context verification
- Role checking
- Company settings retrieval

---

#### 11. Get Current User

**Endpoint**: `GET /user`

**Description**: Returns the authenticated user.

**Authentication**: Required (Sanctum middleware)

**Success Response** (200):

```json
{
  "id": 1,
  "first_name": "John",
  "last_name": "Doe",
  "username": "john.doe@example.com",
  "email": "john.doe@example.com",
  "company_id": 1,
  "status": "Active",
  "created_at": "2025-11-07T10:00:00.000000Z",
  "updated_at": "2025-11-07T10:00:00.000000Z"
}
```

**Error Response** (401):

```json
{
  "message": "Unauthenticated."
}
```

---

## Response Format

All API responses follow a consistent format:

### Success Response

```json
{
  "code": 1,
  "message": "Success message",
  "data": {
    // Response data
  }
}
```

### Error Response

```json
{
  "code": 0,
  "message": "Error message",
  "data": null
}
```

**Response Fields**:
- `code` (integer) - 1 for success, 0 for error
- `message` (string) - Human-readable message
- `data` (object|array|null) - Response payload

---

## Error Codes

| HTTP Status | Code | Meaning |
|-------------|------|---------|
| 200 | 1 | Success |
| 400 | 0 | Bad Request / Validation Error |
| 401 | 0 | Unauthenticated |
| 403 | 0 | Forbidden |
| 404 | 0 | Not Found |
| 500 | 0 | Server Error |

---

## Authentication Flow

### Step 1: Register

```http
POST /auth/register
Content-Type: application/json

{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "password": "password123",
  "company_name": "Acme Corp",
  "currency": "UGX"
}
```

### Step 2: Login

```http
POST /auth/login
Content-Type: application/json

{
  "email": "john@example.com",
  "password": "password123"
}
```

### Step 3: Store Token

Extract the token from the user object in the login response:

```json
{
  "data": {
    "user": {
      "token": "1|abc123xyz..."
    }
  }
}
```

### Step 4: Use Token

Include the token in all subsequent requests:

```http
GET /api/BudgetProgram
Authorization: Bearer 1|abc123xyz...
```

---

## Request Examples

### Create Budget Item

```bash
curl -X POST \
  'https://yourdomain.com/api/budget-item-create' \
  -H 'Authorization: Bearer {token}' \
  -H 'Content-Type: application/json' \
  -d '{
    "budget_program_id": 1,
    "budget_item_category_id": 1,
    "financial_period_id": 1,
    "name": "Office Supplies",
    "amount": 500000,
    "quantity": 1,
    "total": 500000,
    "status": "Pending",
    "date": "2025-11-07"
  }'
```

### List Financial Periods

```bash
curl -X GET \
  'https://yourdomain.com/api/FinancialPeriod' \
  -H 'Authorization: Bearer {token}'
```

### Upload File

```bash
curl -X POST \
  'https://yourdomain.com/api/file-uploading' \
  -H 'Authorization: Bearer {token}' \
  -F 'photo=@/path/to/image.jpg'
```

### Search Stock Items

```bash
curl -X GET \
  'https://yourdomain.com/api/stock-items?q=laptop&company_id=1'
```

---

## Rate Limiting

Currently, there are **no rate limits** implemented. This will be added in future versions.

**Recommendation**: Implement rate limiting in production:
- 60 requests per minute for authenticated users
- 20 requests per minute for public endpoints

---

## Versioning

**Current Version**: v1.0.0

The API currently does not use URL versioning. All endpoints are accessed directly without a version prefix.

**Future Recommendation**: Implement versioning (e.g., `/api/v1/...`)

---

## Common Use Cases

### 1. Mobile App Initialization

```javascript
// Step 1: Login
const loginResponse = await fetch('/auth/login', {
  method: 'POST',
  body: JSON.stringify({ email, password })
});
const { user, company } = loginResponse.data;

// Step 2: Get manifest
const manifestResponse = await fetch('/manifest', {
  headers: { 'Authorization': `Bearer ${user.token}` }
});

// Step 3: Load required data
const periods = await fetch('/api/FinancialPeriod', {
  headers: { 'Authorization': `Bearer ${user.token}` }
});
```

### 2. Creating Budget with File Upload

```javascript
// Step 1: Upload supporting document
const formData = new FormData();
formData.append('photo', file);
const uploadResponse = await fetch('/file-uploading', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` },
  body: formData
});

// Step 2: Create budget item with document
const budgetResponse = await fetch('/budget-item-create', {
  method: 'POST',
  headers: { 
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    ...budgetData,
    document: uploadResponse.data.file_name
  })
});
```

### 3. Generic Model Update

```javascript
// Update any model dynamically
const updateModel = async (modelName, data) => {
  return await fetch(`/api/${modelName}`, {
    method: 'POST',
    headers: { 
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
  });
};

// Usage
await updateModel('BudgetProgram', { 
  id: 1, 
  name: 'Updated Name' 
});
```

---

## Security Best Practices

1. **Always use HTTPS** in production
2. **Store tokens securely** (encrypted storage, not localStorage)
3. **Implement token refresh** mechanism
4. **Validate all inputs** on the client side
5. **Handle errors gracefully** without exposing sensitive data
6. **Implement rate limiting**
7. **Use CORS properly** to restrict API access
8. **Log security events** for audit trails

---

## Support

For API support, contact:
- **Email**: support@budget-pro.com
- **Documentation**: [https://docs.budget-pro.com](https://docs.budget-pro.com)

---

**Last Updated**: November 7, 2025  
**API Version**: 1.0.0
