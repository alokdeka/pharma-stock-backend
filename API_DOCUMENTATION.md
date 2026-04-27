# Pharma-Stock WMS API Documentation

Base URL: `{host}/api`
All routes (except `/auth/login`) require a Bearer token in the `Authorization` header.

Standard JSON Response format for all endpoints:
```json
{
    "success": true,
    "data": { ... },
    "message": "Action successful"
}
```

---

## 1. Authentication

### Login
- **Endpoint**: `POST /auth/login`
- **Desc**: Authenticate to obtain a JWT.
- **Auth**: Public
- **Request Body**:
```json
{
    "email": "manager@pharma.com",
    "password": "secret_password"
}
```

### Logout
- **Endpoint**: `POST /auth/logout`
- **Desc**: Invalidates current token session. (Client-side deletion applied).
- **Auth**: Any authenticated role

---

## 2. Medicine Inventory

### Get All Medicines
- **Endpoint**: `GET /medicines`
- **Desc**: Returns a list of all medicines including aggregated current stock.
- **Auth**: Any authenticated role

### Get Single Medicine
- **Endpoint**: `GET /medicines/{id}`
- **Desc**: Retrieve details of a specific medicine ID.
- **Auth**: Any authenticated role

### Add Medicine
- **Endpoint**: `POST /medicines`
- **Desc**: Adds a new medicine catalog entry.
- **Auth**: Admin
- **Request Body**:
```json
{
    "name": "Paracetamol 500mg",
    "manufacturer": "Sun Pharma",
    "category": "Analgesic",
    "price": 12.50,
    "reorder_point": 100
}
```

### Update Medicine
- **Endpoint**: `PUT /medicines/{id}`
- **Desc**: Partially updates an existing medicine.
- **Auth**: Admin, Manager
- **Request Body**: (Include only what needs to be changed)
```json
{
    "price": 14.50
}
```

### Delete Medicine
- **Endpoint**: `DELETE /medicines/{id}`
- **Desc**: Removes a medicine from the database.
- **Auth**: Admin

---

## 3. Batches & Stock Management

### Get All Batches
- **Endpoint**: `GET /batches`
- **Desc**: Lists all recorded batches.
- **Auth**: Any authenticated role

### Get Single Batch
- **Endpoint**: `GET /batches/{id}`
- **Desc**: Retrieve single batch along with its transaction history.
- **Auth**: Any authenticated role

### Search Batch
- **Endpoint**: `GET /batches/search?batch_number={XY-123}`
- **Desc**: Search for specific batches by their batch number.
- **Auth**: Any authenticated role

### Add / Check-In Batch Stock
- **Endpoint**: `POST /batches`
- **Desc**: Intakes a new batch (registers "stock in").
- **Auth**: Manager
- **Request Body**:
```json
{
    "medicine_id": 1,
    "batch_number": "BATCH-2025-001",
    "mfg_date": "2025-01-01",
    "expiry_date": "2027-01-01",
    "quantity": 500
}
```

### Record Sale (FEFO Output)
- **Endpoint**: `POST /batches/{medicine_id}/sell`
- **Desc**: Sells a quantity of medicine. Will automatically deduct from the earliest expiring batch (First-Expired, First-Out).
- **Auth**: Manager
- **Request Body**:
```json
{
    "quantity": 50,
    "reference": "INV-1023"
}
```
**Returns**: `alert: true` if the stock goes below the `reorder_point`.

---

## 4. Expiry Dashboards

### Full Expiry Overview
- **Endpoint**: `GET /expiry/dashboard`
- **Desc**: Views timeline of expiries with their Green/Yellow/Red indicators.
- **Auth**: Any authenticated role

### Critical Expiries Only
- **Endpoint**: `GET /expiry/critical`
- **Desc**: Retrieves only the Red (<= 30 days) and Yellow (<= 60 days) batches.
- **Auth**: Any authenticated role

---

## 5. Orders and Stock Thresholds

### Low Stock Report
- **Endpoint**: `GET /stock/low`
- **Desc**: Retrieves all medicines that have fallen below their respective `reorder_point`.
- **Auth**: Any authenticated role

### List Purchase Orders
- **Endpoint**: `GET /orders`
- **Desc**: Lists all purchase orders.
- **Auth**: Admin, Manager

### Add Purchase Order
- **Endpoint**: `POST /orders`
- **Desc**: Requests a restock for specific medicines.
- **Auth**: Manager
- **Request Body**:
```json
{
    "medicine_id": 1,
    "quantity": 1000
}
```

### Update Purchase Order Status
- **Endpoint**: `PUT /orders/{id}/status`
- **Desc**: Modifies an existing PO (statuses allowed: `pending`, `approved`, `received`).
- **Auth**: Admin
- **Request Body**:
```json
{
    "status": "approved"
}
```

---

## 6. Real-time Reporting

### Complete Inventory Report
- **Endpoint**: `GET /reports/inventory`
- **Desc**: Shows a summary of every cataloged medicine alongside aggregated stock values.
- **Auth**: Admin, Manager

### Basic Expiry Summary
- **Endpoint**: `GET /reports/expiry-summary`
- **Desc**: Returns aggregate counts of total batches grouped by Red/Yellow/Green limits.
- **Auth**: Any authenticated role

### Batched Transactions Trail
- **Endpoint**: `GET /reports/batch-sales?batch_number={BAT-123}`
- **Desc**: Reports every output transaction associated over a specific batch tracking string.
- **Auth**: Admin, Manager

### Transaction Timeline
- **Endpoint**: `GET /reports/transactions?from={YYYY-MM-DD}&to={YYYY-MM-DD}`
- **Desc**: Export ledger tracking across all ins and outs within a given date boundaries.
- **Auth**: Admin, Manager
