# NVR Desk

একই local network-এ থাকা ১০০+ NVR-এর inventory ও TCP management-port health check-এর জন্য Laravel অ্যাপ।

## চালু করা

WAMP/PHP terminal থেকে project folder-এ:

```powershell
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

তারপর browser-এ `http://127.0.0.1:8000` খুলুন। প্রথমবার database প্রস্তুত হলে dashboard-এ **Add NVR** দিয়ে device যোগ করুন, অথবা **Import CSV** দিয়ে একসাথে যোগ করুন। CSV-এর header হবে:

```csv
name,ip,port,site,vendor,channels
NVR-01,192.168.1.201,80,Main Office,Hikvision,16
```

`name`, `ip`, `port` আবশ্যক। `ip,port` একই হলে import বিদ্যমান recorder-এর তথ্য update করবে। Import-এর সর্বোচ্চ আকার ২ MB / ২,০০০ row।

## Queue ও নিয়মিত check

Health check queue-তে চলে, তাই project folder-এ আলাদা দুটি PowerShell terminal চালু রাখুন:

```powershell
php artisan queue:work database --queue=nvr-health,default --sleep=1 --tries=1 --timeout=10
```

```powershell
php artisan schedule:work
```

Scheduler প্রতি মিনিটে enabled NVR-এর check queue-তে পাঠায়। Dashboard ১০ সেকেন্ড পরপর নতুন ফলাফল নেয়। বড় inventory-তে একাধিক queue worker ব্যবহার করা যায়; Windows Task Scheduler দিয়ে server, worker ও scheduler স্থায়ীভাবে চালানো উত্তম।

## Check-এর অর্থ ও সীমাবদ্ধতা

- Status নির্ধারিত IP ও TCP port-এ সংযোগ হচ্ছে কি না তা জানায়; `Online` মানে শুধু configured port সাড়া দিয়েছে।
- সাধারণ web-management port `80` দিয়ে শুরু করুন, তবে আপনার NVR-এ ভিন্ন port হলে সেটি দিন। ভুল port হলে ডিভাইস চালু থাকলেও `Offline` দেখা যেতে পারে।
- এটি এখনো vendor API/ONVIF, camera channel-এর live video, disk/recording health, playback, alert delivery বা user login/role পরীক্ষা করে না। সেগুলোর জন্য NVR brand/model ও protocol-ভিত্তিক integration প্রয়োজন।
- অ্যাপটি default-এ শুধু `127.0.0.1`-এ চালান। এখানে login নেই; Apache/WAMP দিয়ে LAN বা internet-এ উন্মুক্ত করবেন না—আগে authentication/authorization যোগ করতে হবে।
- SQLite default configuration ছোট local deployment-এর জন্য রাখা হয়েছে। বহু concurrent operator বা production retention প্রয়োজন হলে `.env`-এ MySQL ব্যবহার করুন এবং আলাদা backup/retention পরিকল্পনা করুন।
