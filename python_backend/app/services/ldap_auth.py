from __future__ import annotations

from ldap3 import NONE, Connection, Server
from ldap3.utils.conv import escape_filter_chars

from app.config import Settings


class LdapAuthenticator:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings

    def authenticate(self, username: str, password: str) -> bool:
        settings = self._settings
        if not all(
            (
                settings.ldap_host,
                settings.ldap_user,
                settings.ldap_password,
                settings.ldap_base_dn,
            )
        ):
            raise RuntimeError("LDAP authentication is not configured")

        host = settings.ldap_host.removeprefix("ldaps://").removeprefix("ldap://")
        use_ssl = settings.ldap_host.startswith("ldaps://")
        server = Server(
            host,
            port=settings.ldap_port,
            use_ssl=use_ssl,
            get_info=NONE,
            connect_timeout=5,
        )
        search_filter = settings.ldap_filter.format(
            username=escape_filter_chars(username)
        )
        with Connection(
            server,
            user=settings.ldap_user,
            password=settings.ldap_password,
            auto_bind=True,
            receive_timeout=5,
        ) as directory:
            if not directory.search(
                settings.ldap_base_dn,
                search_filter,
                attributes=["cn"],
            ):
                return False
            if len(directory.entries) != 1:
                return False
            user_dn = directory.entries[0].entry_dn

        with Connection(
            server,
            user=user_dn,
            password=password,
            auto_bind=True,
            receive_timeout=5,
        ):
            return True
