-- Register-type event sign-ups now go through an approval step: a youth
-- registering lands in 'pending' (a pre-registration request only — the
-- SK/PPSK office still needs to settle fees/documents in person), and the
-- managing office Accepts (-> registered) or Declines (-> declined) it.
-- 'declined' is kept distinct from 'cancelled' (the youth withdrawing
-- themselves) since they mean different things for reporting/history.

ALTER TABLE event_participants
    MODIFY COLUMN status ENUM('interested','pending','registered','attended','declined','cancelled','no_show')
    NOT NULL DEFAULT 'registered';
