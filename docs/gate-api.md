# Gate Vehicle Access API

## Overview

The Gate API is used exclusively by the hardware gate / LPR (License Plate Recognition) / OCR devices to verify whether a vehicle is allowed to enter the university campus based on approved student vehicle requests.

---

## Endpoint

```http
POST /api/v1/gate/vehicle-access/check
```

### Required Headers

| Header | Value | Description |
|--------|-------|-------------|
| `X-GATE-API-KEY` | *Your Gate API Key* | Must match `GATE_API_KEY` in backend `.env` |
| `Content-Type` | `application/json` | Required for JSON payload |
| `Accept` | `application/json` | Required for JSON response |

---

## Request Format

The OCR system should send the detected plate text in a JSON body.

```json
{
  "OCR": "س م ١ ٤ ٦ ٩"
}
```

### Normalization Logic

The backend automatically normalizes the incoming OCR text before matching it against the database:
- Converts Arabic and Persian numerals (`٠-٩`, `۰-۹`) to English numerals (`0-9`).
- Standardizes Arabic letters (e.g., `أ`, `إ`, `آ` become `ا`).
- Removes all spaces, dashes, dots, underscores, slashes, and pipes.
- Converts the final string to lowercase.

This ensures robust matching even if the OCR spacing or numeral type differs from the student's input.

---

## Responses

### 1. Allowed (200 OK)

Returned if the student has an **approved** permit that is **currently valid** (today falls between `semester_start_date` and `semester_end_date`).

```json
{
  "success": true,
  "access": "allowed",
  "message": "Vehicle permit is approved and valid.",
  "data": {
    "plate_number": "س م ١ ٤ ٦ ٩",
    "normalized_plate": "سم1469",
    "student": {
      "student_id": "YOUR_STUDENT_ID",
      "full_name": "Student Name",
      "faculty": "Faculty Name"
    },
    "permit": {
      "id": 1,
      "valid_from": "2026-02-01",
      "valid_until": "2026-06-30"
    }
  }
}
```

### 2. Denied (200 OK)

Returned if no valid permit exists. This includes:
- Pending requests.
- Rejected requests.
- Expired permits.
- No matching plate found.

```json
{
  "success": true,
  "access": "denied",
  "message": "No approved valid vehicle permit found for this plate.",
  "data": {
    "plate_number": "س م ١ ٤ ٦ ٩",
    "normalized_plate": "سم1469"
  }
}
```

### 3. Unauthorized (401 Unauthorized)

Returned if the `X-GATE-API-KEY` header is missing or incorrect.

```json
{
  "success": false,
  "message": "Unauthorized gate device."
}
```
