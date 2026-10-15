<?php
setcookie("lx_auth_token", "", time() - 3600, "/", ".leronx.org");
setcookie("lx_user_id", "", time() - 3600, "/", ".leronx.org");
header("Location: /");
exit;
