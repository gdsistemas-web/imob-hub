# Conector PAM isolado

Processo separado porque `pushinbr/pam-whatsapp-web` 1.x exige PHP 8.5, enquanto o CRM permanece em PHP 8.3. Requer PAM, PHP 8.5 e Chrome/Chromium. A sessão fica em `PAM_SESSION_PATH`, fora do Git e da pasta pública.

```bash
pam doctor
pam composer install --working-dir=connector-pam
CONNECTOR_INTERNAL_TOKEN='igual-ao-backend' PAM_SESSION_PATH=/srv/imob-hub-pam-session \
  CRM_INTERNAL_URL=http://127.0.0.1:8080/api/internal/messages pam connector-pam/listen.php
```

Na primeira inicialização, o QR aparece somente no terminal administrativo. Use systemd ou Supervisor na VPS com reinício automático, usuário dedicado e diretório de sessão modo `0700`. Este conector é WhatsApp Web não oficial e não substitui a WhatsApp Business Platform.
