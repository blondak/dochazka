CREATE TABLE device_warning (
 device integer NOT NULL REFERENCES device, message text NOT NULL,
 received timestamptz NOT NULL DEFAULT now(), acknowledged timestamptz, PRIMARY KEY(device,message)
);
