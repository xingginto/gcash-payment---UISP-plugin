# GCash Payment - UISP Plugin

Accept GCash payments via QR code with payment verification and automatic posting to client accounts in UISP/UCRM.

## Features

- **Public Payment Page**: Customers can pay using their account number
- **QR Code Display**: Show your GCash QR code for easy scanning
- **Reference Verification**: Customers submit GCash reference numbers
- **Admin Dashboard**: View, approve, or reject pending payments
- **Auto-posting**: Approved payments are automatically posted to UISP
- **Payment Tracking**: Track all payment history with status filtering
- **Duplicate Prevention**: Reference numbers can only be used once

## Requirements

- **UCRM Version**: 2.14.0 or higher
- **UISP Version**: 1.0.0 or higher
- **PHP Version**: 7.4 or higher

## Installation

1. Download the plugin ZIP file
2. Go to UISP → System → Plugins
3. Click "Add Plugin" and upload the ZIP
4. Enable the plugin
5. Configure your GCash details in plugin settings

## Configuration

| Setting | Description | Required |
|---------|-------------|----------|
| GCash Number | Your GCash mobile number | Yes |
| GCash Account Name | Name on your GCash account | Yes |
| GCash QR Code URL | URL to your QR code image | No |
| UISP Payment Method ID | UUID of payment method | No |
| Auto-approve | Auto-approve payments (0/1) | No |
| Notification Email | Email for notifications | No |

### Getting Your GCash QR Code

1. Open GCash app
2. Go to "Receive Money"
3. Screenshot or save your QR code
4. Upload to cloud storage (Google Drive, Imgur, etc.)
5. Paste the direct image URL in settings

### Setting Up Payment Method in UISP

1. Go to UISP → System → Other → Payment Methods
2. Create a new method called "GCash"
3. Copy the UUID and paste in plugin settings

## How It Works

### For Customers

1. Visit the public payment page
2. Enter account number and amount
3. Scan QR code or send to GCash number
4. Enter GCash reference number from receipt
5. Wait for verification

### For Administrators

1. Go to Reports → GCash Payments
2. Review pending payments
3. Verify reference numbers in your GCash app
4. Click "Approve" to post payment to UISP
5. Or "Reject" if payment is invalid

## Payment Verification

Since GCash doesn't provide a public API for payment verification, administrators should:

1. Check GCash app for received payments
2. Match reference numbers with pending payments
3. Verify amounts match before approving

### Tips for Verification

- Check GCash transaction history
- Match reference number exactly
- Verify amount matches
- Look at payment timestamp

## Security

- Admin pages require UISP authentication
- Reference numbers can only be used once
- All inputs are sanitized
- Payment data stored locally in plugin

## File Storage

Pending payments are stored in:
```
/data/pending_payments.json
```

## API Endpoints Used

- `GET /clients` - Find client by account number
- `POST /payments` - Create payment in UISP

## Troubleshooting

### Payment Not Posting

- Check UISP API permissions
- Verify client ID is correct
- Check plugin logs for errors

### QR Code Not Showing

- Ensure URL is a direct image link
- Check if image URL is accessible
- Try uploading to different host

### Reference Number Already Used

- Each reference can only be submitted once
- Check if payment was already approved
- Contact customer for new payment if needed

## Author

**xingginto**

## License

MIT License

## Version History

- **v1.0.0**: Initial release with QR code payment, verification, and auto-posting
