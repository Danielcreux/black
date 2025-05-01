<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generador QR</title>
</head>
<body style="background-color: black; color: white; font-family: sans-serif; text-align: center; padding-top: 50px;">
  <h1>Generador de Código QR (Versión 2)</h1>
  <form action="qr_generator_v2.php" method="post" target="qrresult">
    <input type="text" name="text" placeholder="Texto a codificar" maxlength="44" required style="width: 400px; padding: 10px; font-size: 16px;">
    <br><br>
    <label for="ecc">Nivel de corrección:</label>
    <select name="ecc" id="ecc" style="padding: 5px; font-size: 16px;">
      <option value="L">L (baja)</option>
      <option value="M">M</option>
      <option value="Q">Q</option>
      <option value="H">H (alta)</option>
    </select>
    <br><br>
    <button type="submit" style="padding: 10px 20px; font-size: 16px;">Generar QR</button>
  </form>

  <h2>Resultado:</h2>
  <iframe name="qrresult" width="260" height="260" style="border: none; background: white;"></iframe>
</body>
</html>
